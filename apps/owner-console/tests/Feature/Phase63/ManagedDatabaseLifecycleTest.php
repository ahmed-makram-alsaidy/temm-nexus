<?php

namespace Tests\Feature\Phase63;

use App\Filament\Pages\NewProjectWizard;
use App\Filament\Resources\Projects\Pages\ProjectConnections;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AdminAuditEntry;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\EnvironmentService;
use App\Services\ControlPlane\ManagedPostgres;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\ProjectDatabaseProvisioner;
use App\Services\ControlPlane\ProjectHealthService;
use App\Support\ActivityHumanizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FakeManagedPostgres extends ManagedPostgres
{
    public array $databases = [];

    public array $roles = [];

    public array $passwords = [];

    public int $roleCreates = 0;

    public int $databaseCreates = 0;

    public int $grants = 0;

    public bool $failDatabase = false;

    public bool $connectable = true;

    public function coordinates(): array
    {
        return ['host' => 'fixture-postgres', 'port' => 5432, 'sslmode' => 'prefer'];
    }

    public function locked(string $key, callable $action): mixed
    {
        return $action();
    }

    public function catalog(string $database, string $role): array
    {
        return ['database' => $this->databases[$database] ?? null, 'role' => $this->roles[$role] ?? null];
    }

    public function createRole(string $role, string $password, string $marker): void
    {
        $this->roleCreates++;
        $this->passwords[$role] = $password;
        $this->roles[$role] = ['marker' => $marker, 'rolcanlogin' => true, 'rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolreplication' => false];
    }

    public function createDatabase(string $database, string $role, string $marker): void
    {
        if ($this->failDatabase) {
            throw new \RuntimeException('unsafe driver detail');
        }
        $this->databaseCreates++;
        $this->databases[$database] = ['owner' => $role, 'marker' => $marker];
    }

    public function secureDatabase(string $database, string $role): void
    {
        $this->grants++;
    }

    public function verify(array $connection): bool
    {
        return $this->connectable;
    }
}

class ManagedDatabaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeManagedPostgres $postgres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postgres = new FakeManagedPostgres;
        $this->app->instance(ManagedPostgres::class, $this->postgres);
        $this->actingAs(User::create(['name' => 'Owner', 'email' => uniqid().'@test.local', 'password' => 'password-password', 'platform_role' => Roles::PLATFORM_OWNER]));
    }

    private function project(): Project
    {
        $workspace = Workspace::create(['name' => 'Fixture', 'slug' => uniqid('fixture-'), 'kind' => 'client', 'status' => 'active']);
        $project = Project::create(['name' => 'Fixture', 'slug' => uniqid('fixture-'), 'workspace_id' => $workspace->id,
            'db_name' => 'fixture_'.bin2hex(random_bytes(5)).'_db', 'environment' => 'development', 'status' => 'active']);
        EnvironmentService::ensureDefaults($project);

        return $project;
    }

    private function service(): ProjectDatabaseProvisioner
    {
        return app(ProjectDatabaseProvisioner::class);
    }

    public static function environments(): array
    {
        return [['development'], ['staging'], ['production']];
    }

    #[DataProvider('environments')]
    public function test_wizard_creates_the_selected_environment_as_the_single_active_default(string $type): void
    {
        $fixture = $this->project();
        $wizard = app(NewProjectWizard::class);
        $wizard->state['name'] = 'Selected '.$type.' '.uniqid();
        $wizard->state['workspace_id'] = $fixture->workspace_id;
        $wizard->state['environment'] = $type;
        $project = (new \ReflectionMethod(NewProjectWizard::class, 'createProject'))->invoke($wizard);
        $this->assertSame(3, $project->environments()->count());
        $this->assertSame(1, $project->environments()->where('is_default', true)->count());
        $environment = EnvironmentContext::active($project);
        $this->assertSame($type, $environment->type);
        $this->assertSame('active', $environment->status);
        $this->assertSame($project->db_name, $this->service()->databaseName($project, $environment));
    }

    public function test_form_cannot_bind_a_different_environment_after_a_switch(): void
    {
        $project = $this->project();
        $component = Livewire::test(ProjectConnections::class, ['record' => $project->id])->call('configureConnection');
        $staging = $project->environments()->where('type', 'staging')->firstOrFail();
        $staging->update(['status' => 'active']);
        Session::put(EnvironmentContext::sessionKey($project), $staging->id);
        $component->set('connectionPassword', 'do-not-leak-after-switch')->call('saveConnection')
            ->assertSet('connectionPassword', '')->assertDontSee('do-not-leak-after-switch');
        $this->assertSame(0, ProjectSecret::count());
        $this->assertNull($staging->fresh()->database_connection);
    }

    public function test_reader_sees_recovery_guidance_but_cannot_provision(): void
    {
        $project = $this->project();
        $reader = User::create(['name' => 'Reader', 'email' => uniqid().'@test.local', 'password' => 'password-password']);
        WorkspaceMember::create(['workspace_id' => $project->workspace_id, 'user_id' => $reader->id, 'role' => Roles::WORKSPACE_MEMBER, 'status' => 'active']);
        $this->actingAs($reader);
        $component = Livewire::test(ProjectConnections::class, ['record' => $project->id])
            ->assertSee(__('connections.owner_required'))->assertDontSeeHtml('wire:click="provisionDatabase"');
        $component->call('provisionDatabase')->assertForbidden();
        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_managed_destination_confirmation_provisions_before_analysis(): void
    {
        $project = $this->project();
        Livewire::test(NewProjectWizard::class)->set('projectId', $project->id)->set('state.destination', 'temm')
            ->set('step', 3)->call('continue')->assertSet('step', 4);
        $this->assertSame(1, $this->postgres->roleCreates);
        $this->assertNotEmpty(EnvironmentContext::active($project)->database_connection);
    }

    public function test_credentials_are_encrypted_and_data_and_real_target_share_the_binding(): void
    {
        $project = $this->project();
        $environment = $this->service()->provision($project);
        $secret = ProjectSecret::where('name', $environment->database_secret_ref)->firstOrFail();
        $configuration = ProjectConnectionManager::configuration($project);
        $target = ProjectConnectionManager::migrationTarget($project);
        $this->assertSame($environment->id, $secret->environment_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret->value);
        $this->assertNotSame($secret->value, $secret->getRawOriginal('value'));
        $this->assertSame($secret->value, $configuration['password']);
        foreach (['host', 'port', 'database', 'username', 'sslmode'] as $key) {
            $this->assertSame($configuration[$key], $target[$key]);
        }
        $this->assertSame(['password' => $secret->name], $target['secret_refs']);
        $this->assertArrayNotHasKey('password', $target);
        $this->assertArrayNotHasKey('password', $environment->database_connection);
        $this->assertStringNotContainsString($secret->value, AdminAuditEntry::all()->toJson());
        $this->assertStringNotContainsString($secret->value, $environment->toJson());
        $this->assertSame($configuration, config('database.connections.'.ProjectConnectionManager::connection($project)));
    }

    public function test_retry_does_not_create_or_change_existing_managed_objects_or_password(): void
    {
        $project = $this->project();
        $first = $this->service()->provision($project);
        $password = ProjectConnectionManager::configuration($project)['password'];
        $second = $this->service()->provision($project);
        $this->assertSame($first->database_connection, $second->database_connection);
        $this->assertSame($password, ProjectConnectionManager::configuration($project)['password']);
        $this->assertSame(1, $this->postgres->roleCreates);
        $this->assertSame(1, $this->postgres->databaseCreates);
        $this->assertSame(1, $this->postgres->grants);
        $this->assertSame(1, ProjectSecret::count());
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'PROJECT_DATABASE_RECONCILED']);
    }

    public function test_partial_failure_retries_using_the_original_encrypted_password(): void
    {
        $project = $this->project();
        $this->postgres->failDatabase = true;
        try {
            $this->service()->provision($project);
            $this->fail('Must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('connections.provision_failed'), $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $secret = ProjectSecret::firstOrFail();
        $this->assertNull(EnvironmentContext::active($project)->database_connection);
        $this->postgres->failDatabase = false;
        $this->service()->provision($project);
        $this->assertSame(1, $this->postgres->roleCreates);
        $this->assertSame($secret->value, ProjectConnectionManager::configuration($project)['password']);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'PROJECT_DATABASE_PROVISIONING_FAILED']);
    }

    public static function existingObjects(): array
    {
        return [['database'], ['role'], ['both']];
    }

    #[DataProvider('existingObjects')]
    public function test_unknown_existing_database_or_role_is_never_overwritten(string $kind): void
    {
        $project = $this->project();
        if ($kind !== 'role') {
            $this->postgres->databases[$project->db_name] = ['owner' => 'existing_owner', 'marker' => null];
        }
        if ($kind !== 'database') {
            $this->postgres->roles[$project->db_name.'_user'] = ['marker' => 'someone else'];
        }
        $before = [$this->postgres->databases, $this->postgres->roles];
        try {
            $this->service()->provision($project);
            $this->fail('Must refuse');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('connections.existing_conflict'), $e->getMessage());
        }
        $this->assertSame($before, [$this->postgres->databases, $this->postgres->roles]);
        $this->assertSame(0, $this->postgres->roleCreates + $this->postgres->databaseCreates + $this->postgres->grants);
        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_pending_role_ownership_conflict_does_not_change_it(): void
    {
        $project = $this->project();
        $this->postgres->failDatabase = true;
        try {
            $this->service()->provision($project);
        } catch (\RuntimeException) {
        }
        $this->postgres->roles[$project->db_name.'_user']['marker'] = 'different owner';
        $this->postgres->failDatabase = false;
        try {
            $this->service()->provision($project);
            $this->fail('Must refuse');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $this->postgres->roleCreates);
        $this->assertSame(0, $this->postgres->databaseCreates + $this->postgres->grants);
    }

    public function test_environment_connections_and_secrets_remain_isolated(): void
    {
        $project = $this->project();
        $development = $this->service()->provision($project);
        $staging = $project->environments()->where('type', 'staging')->firstOrFail();
        $staging->update(['status' => 'active']);
        $staging = $this->service()->provision($project, $staging);
        $this->assertNotSame($development->database_connection['database'], $staging->database_connection['database']);
        $this->assertNotSame($development->database_secret_ref, $staging->database_secret_ref);
        $this->assertNotSame(ProjectConnectionManager::connectionName($project, $development), ProjectConnectionManager::connectionName($project, $staging));
        EnvironmentContext::switch($project, $staging->id);
        $this->assertSame($staging->database_connection['database'], ProjectConnectionManager::migrationTarget($project)['database']);
    }

    public function test_cross_environment_secret_reference_is_rejected(): void
    {
        $project = $this->project();
        $environment = $this->service()->provision($project);
        ProjectSecret::firstOrFail()->update(['environment_id' => $project->environments()->where('type', 'staging')->value('id')]);
        $this->expectException(\RuntimeException::class);
        ProjectConnectionManager::configuration($project, $environment);
    }

    public function test_manual_connection_is_verified_and_vaulted_without_ddl(): void
    {
        $project = $this->project();
        $configuration = ['host' => 'external.fixture', 'port' => 5433, 'database' => 'existing', 'username' => 'existing_user', 'password' => 'manual-secret-not-in-response', 'sslmode' => 'require'];
        $environment = $this->service()->configure($project, EnvironmentContext::active($project), $configuration);
        $this->assertSame('external', $environment->config['destination']);
        $this->assertSame($configuration['password'], ProjectConnectionManager::configuration($project)['password']);
        $this->assertSame(0, $this->postgres->roleCreates + $this->postgres->databaseCreates + $this->postgres->grants);
        $this->assertStringNotContainsString($configuration['password'], AdminAuditEntry::all()->toJson());
    }

    public function test_manual_configuration_cannot_replace_a_pending_retry_password(): void
    {
        $project = $this->project();
        $this->postgres->failDatabase = true;
        try {
            $this->service()->provision($project);
        } catch (\RuntimeException) {
        }
        $password = ProjectSecret::firstOrFail()->value;
        try {
            $this->service()->configure($project, EnvironmentContext::active($project), ['password' => 'new']);
            $this->fail('Must refuse');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('connections.pending_provisioning'), $e->getMessage());
        }
        $this->assertSame($password, ProjectSecret::firstOrFail()->value);
    }

    public static function locales(): array
    {
        return [['en'], ['ar']];
    }

    #[DataProvider('locales')]
    public function test_connections_has_actions_without_self_navigation_or_secret_reveal(string $locale): void
    {
        app()->setLocale($locale);
        $project = $this->project();
        $project->update(['environment' => 'production']);
        Livewire::test(ProjectConnections::class, ['record' => $project->id])
            ->assertSee(__('connections.not_provisioned'))->assertSee(__('connections.provision'))
            ->assertSee(__('connections.project_classification').': '.__('projects.env_production'))
            ->assertSee(__('connections.active_environment'))->assertSee(__('connections.environment_scope_help'))
            ->assertSee(__('connections.provision_for', ['environment' => __('projects.env_development')]))
            ->assertSee(__('projects.env_development'))
            ->assertSee(__('connections.configure'))->assertDontSee(__('foundation.db_unreachable_next'));
        foreach (['PROVISIONING_STARTED', 'PROVISIONED', 'PROVISIONING_FAILED', 'RECONCILED', 'CONFIGURED'] as $suffix) {
            $action = 'PROJECT_DATABASE_'.$suffix;
            $this->assertSame(__('activity.'.strtolower($action)), ActivityHumanizer::humanize($action));
        }
        $environment = EnvironmentContext::active($project);
        $environment->update(['config' => ['destination' => 'external']]);
        Livewire::test(ProjectConnections::class, ['record' => $project->id])
            ->assertSee(__('connections.not_configured'))->assertSee(__('connections.configure'))
            ->assertDontSee(__('connections.not_provisioned'));
    }

    public function test_password_is_cleared_after_validation_or_verification_failure(): void
    {
        $project = $this->project();
        $component = Livewire::test(ProjectConnections::class, ['record' => $project->id])->call('configureConnection');
        $component->set('connectionPassword', 'never-echo-password')->call('saveConnection')
            ->assertHasErrors(['connectionForm.host'])->assertSet('connectionPassword', '')->assertDontSee('never-echo-password');
        $this->postgres->connectable = false;
        $component->set('connectionForm.host', 'test.local')->set('connectionForm.username', 'fixture')
            ->set('connectionPassword', 'never-echo-password')->call('saveConnection')->assertSet('connectionPassword', '')
            ->assertDontSee('never-echo-password');
        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_provisioning_and_configuration_require_scoped_permissions(): void
    {
        $project = $this->project();
        $user = User::create(['name' => 'Unprivileged', 'email' => uniqid().'@test.local', 'password' => 'password-password']);
        $this->actingAs($user);
        foreach (['provision', 'configure'] as $method) {
            try {
                $method === 'provision' ? $this->service()->provision($project) : $this->service()->configure($project, EnvironmentContext::active($project), []);
                $this->fail('Must deny');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(0, $this->postgres->roleCreates);
        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_workspace_owner_cannot_provision_another_workspace_project(): void
    {
        $project = $this->project();
        $other = $this->project();
        $user = User::create(['name' => 'Scoped Owner', 'email' => uniqid().'@test.local', 'password' => 'password-password']);
        WorkspaceMember::create(['workspace_id' => $project->workspace_id, 'user_id' => $user->id, 'role' => Roles::WORKSPACE_OWNER, 'status' => 'active']);
        $this->actingAs($user);
        $this->service()->provision($project);
        $this->expectException(HttpException::class);
        $this->service()->provision($other);
    }

    public function test_inactive_or_foreign_environment_cannot_be_provisioned(): void
    {
        $project = $this->project();
        $other = $this->project();
        foreach ([[$project->environments()->where('type', 'production')->firstOrFail(), 422], [EnvironmentContext::active($other), 404]] as [$environment, $status]) {
            try {
                $this->service()->provision($project, $environment);
                $this->fail('Must deny');
            } catch (HttpException $e) {
                $this->assertSame($status, $e->getStatusCode());
            }
        }
        $this->assertSame(0, $this->postgres->roleCreates);
    }

    public function test_english_and_arabic_translation_keys_match(): void
    {
        $this->assertSame(array_keys(require lang_path('en/connections.php')), array_keys(require lang_path('ar/connections.php')));
    }

    /** Run against an isolated PostgreSQL fixture, never a customer database. */
    public function test_real_postgres_provisioning_data_pages_and_real_target(): void
    {
        if (! getenv('PROVISIONING_TEST_PG_PORT')) {
            $this->markTestSkipped('Isolated PostgreSQL fixture required.');
        }
        config(['managed-database' => ['host' => '127.0.0.1', 'port' => (int) getenv('PROVISIONING_TEST_PG_PORT'), 'username' => 'fixture_admin', 'password' => 'fixture-admin-only', 'sslmode' => 'disable']]);
        $this->app->instance(ManagedPostgres::class, new ManagedPostgres);
        $project = $this->project();
        $environment = $this->service()->provision($project);
        $name = ProjectConnectionManager::connection($project);
        DB::connection($name)->statement('CREATE TABLE proof_rows (id integer PRIMARY KEY, label text)');
        DB::connection($name)->insert('INSERT INTO proof_rows VALUES (1, ?)', ['preserved']);
        $this->service()->provision($project);
        $this->assertSame('preserved', DB::connection($name)->selectOne('SELECT label FROM proof_rows WHERE id = 1')->label);
        $this->assertTrue(ProjectConnectionManager::ping($project));
        $catalog = app(ManagedPostgres::class)->catalog($environment->database_connection['database'], $environment->database_connection['username']);
        $this->assertFalse($catalog['role']['rolsuper']);
        $this->assertFalse($catalog['role']['rolcreatedb']);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->get(ProjectResource::getUrl('connections', ['record' => $project]))->assertOk()->assertSee(__('connections.connected'));
            $this->get(ProjectResource::getUrl('database', ['record' => $project]))->assertOk()->assertSee('proof_rows')->assertDontSee(__('foundation.db_unreachable_title'));
            $this->get(ProjectResource::getUrl('db-health', ['record' => $project]))->assertOk()
                ->assertSee(__('connections.connected'))->assertSee(__('connections.source_separate'))
                ->assertSee($environment->database_connection['database'])->assertDontSee(__('foundation.db_unreachable_title'));
        }
        $health = ProjectHealthService::for($project)->check();
        $this->assertTrue($health['database']['reachable']);
        $this->assertSame('project_database', $health['database']['scope']);
        $this->assertSame($environment->id, $health['database']['environment_id']);
        $this->assertGreaterThan(0, $health['database']['size_bytes']);
        $secret = ProjectSecret::where('name', $environment->database_secret_ref)->firstOrFail();
        $response = $this->get(ProjectResource::getUrl('connections', ['record' => $project]));
        $response->assertDontSee($secret->value);
        $target = ProjectConnectionManager::migrationTarget($project);
        $this->assertSame($environment->database_connection['database'], $target['database']);
        $this->assertSame($secret->name, $target['secret_refs']['password']);
        $this->assertArrayNotHasKey('password', $target);
        Session::forget(EnvironmentContext::sessionKey($project));
    }
}
