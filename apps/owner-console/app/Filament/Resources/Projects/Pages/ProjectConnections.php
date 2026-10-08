<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\ProjectDatabaseProvisioner;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

class ProjectConnections extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public bool $configuring = false;

    public array $connectionForm = [];

    public string $connectionPassword = '';

    #[Locked]
    public ?int $configurationEnvironmentId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.connections');
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => __('labels.tables'), __('labels.connections')];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])
            ->components([$this->subnavSection('connections'), EmbeddedSchema::make('infolist')]);
    }

    public function connectionState(): array
    {
        $project = $this->project();
        $environment = EnvironmentContext::active($project);
        $metadata = array_intersect_key($environment->database_connection ?? [], array_flip(['host', 'port', 'database', 'username', 'sslmode']));
        $configured = false;
        try {
            $configuration = ProjectConnectionManager::configuration($project, $environment);
            $metadata = array_intersect_key($configuration, array_flip(['host', 'port', 'database', 'username', 'sslmode']));
            $configured = true;
        } catch (\RuntimeException) {
        }

        return [
            'configured' => $configured,
            'reachable' => $configured && ProjectConnectionManager::ping($project),
            'managed' => ($environment->config['destination'] ?? 'temm') === 'temm',
            'pending' => ! $configured && isset($environment->config['database_provisioning']),
            'canManage' => $environment->status === 'active' && ProjectDatabaseProvisioner::canManage($project),
            'environment' => in_array($environment->slug, ['development', 'staging', 'production'], true)
                ? __('projects.env_'.$environment->type) : $environment->name,
            'classification' => __('projects.env_'.$project->environment),
            'metadata' => $metadata,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.resources.projects.connections')->viewData($this->connectionState())]);
    }

    public function provisionDatabase(): void
    {
        abort_unless(ProjectDatabaseProvisioner::canManage($this->project()), 403);
        try {
            app(ProjectDatabaseProvisioner::class)->provision($this->project());
            Notification::make()->title(__('connections.provisioned'))->success()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function configureConnection(): void
    {
        abort_unless(ProjectDatabaseProvisioner::canManage($this->project()), 403);
        $environment = EnvironmentContext::active($this->project());
        $this->configurationEnvironmentId = $environment->id;
        $this->connectionForm = array_merge([
            'host' => $this->project()->db_host ?? '', 'port' => $this->project()->db_port ?? 5432,
            'database' => $this->project()->db_name ?? '', 'username' => '', 'sslmode' => 'prefer',
            'destination' => $environment->config['destination'] ?? 'temm',
        ], array_intersect_key($environment->database_connection ?? [], array_flip(['host', 'port', 'database', 'username', 'sslmode'])));
        $this->connectionPassword = '';
        $this->configuring = true;
    }

    public function saveConnection(): void
    {
        abort_unless(ProjectDatabaseProvisioner::canManage($this->project()), 403);
        try {
            abort_unless($this->configurationEnvironmentId === EnvironmentContext::active($this->project())->id,
                422, __('connections.environment_changed'));
            $this->validate([
                'connectionForm.host' => ['required', 'string', 'max:255', 'not_regex:/[;\s]/'],
                'connectionForm.port' => ['required', 'integer', 'between:1,65535'],
                'connectionForm.database' => ['required', 'string', 'max:63', 'not_regex:/[;\s]/'],
                'connectionForm.username' => ['required', 'string', 'max:63'],
                'connectionForm.sslmode' => ['required', 'in:disable,allow,prefer,require,verify-ca,verify-full'],
                'connectionForm.destination' => ['required', 'in:temm,external'],
                'connectionPassword' => ['required', 'string', 'max:8000'],
            ]);
            app(ProjectDatabaseProvisioner::class)->configure($this->project(), EnvironmentContext::active($this->project()),
                array_intersect_key($this->connectionForm, array_flip(['host', 'port', 'database', 'username', 'sslmode']))
                    + ['password' => $this->connectionPassword], $this->connectionForm['destination']);
            $this->configuring = false;
            $this->configurationEnvironmentId = null;
            Notification::make()->title(__('connections.configured'))->success()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } finally {
            $this->connectionPassword = '';
        }
    }

    public function cancelConfiguration(): void
    {
        $this->connectionPassword = '';
        $this->configurationEnvironmentId = null;
        $this->configuring = false;
    }

    public function retryConnection(): void
    {
        ProjectConnectionManager::forget($this->project());
    }
}
