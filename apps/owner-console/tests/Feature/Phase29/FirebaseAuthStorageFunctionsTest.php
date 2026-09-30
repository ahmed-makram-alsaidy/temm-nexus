<?php

namespace Tests\Feature\Phase29;

use App\Connectors\Firebase\FirebaseAuthAnalyzer;
use App\Connectors\Firebase\FirebaseFunctionsAnalyzer;
use App\Connectors\Firebase\FirebaseStorageAnalyzer;
use App\Connectors\Firebase\FirebaseSourceAdapter;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29F/29G/29H — Auth inventory with NO password material, Storage
 * inventory with honest copy strategies, Functions inventory with honest
 * classification.
 */
class FirebaseAuthStorageFunctionsTest extends TestCase
{
    use RefreshDatabase;

    protected function adapterInventory(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P29 ASF', 'slug' => 'p29-asf-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p29f.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('firebase');
        $source = $connector->createSourceProfile($project, [], [
            'project_id' => 'temm-dogfood-sandbox',
            'transport' => 'fixture',
        ]);
        $source->refresh();

        return (new FirebaseSourceAdapter($source))->inventory();
    }

    // ── 29F — Firebase Auth ──────────────────────────────────────────────

    public function test_auth_inventory_records_shapes_without_credential_material(): void
    {
        $inventory = $this->adapterInventory();
        $auth = $inventory['firebase']['auth'];

        $this->assertSame(4, count($auth['users']));
        $this->assertSame(2, $auth['password_users']);
        $this->assertSame(1, $auth['anonymous_users']);
        $this->assertSame(0, $auth['oauth_only_users'], 'fixture has no oauth-ONLY user (u002 also has password)');
        $this->assertSame(1, $auth['disabled_users']);
        $this->assertSame('password', array_search(2, $auth['providers']), 'password provider counted');
        $this->assertContains('google.com', array_keys($auth['providers']));
        $this->assertContains('anonymous', array_keys($auth['providers']));
    }

    public function test_password_portability_is_never_faked(): void
    {
        $inventory = $this->adapterInventory();
        $auth = $inventory['firebase']['auth'];
        $this->assertSame('NEEDS_REVIEW', $auth['migration']['password_compatibility']);
        $this->assertSame('not_exposed_by_listing', $auth['migration']['hash_strategy']);

        $decisions = array_column($auth['users'], 'migration_decision', 'uid');
        $this->assertSame('NEEDS_REVIEW', $decisions['u001'], 'password user');
        $this->assertSame('NEEDS_REVIEW', $decisions['u002'], 'oauth+password → password present, so NEEDS_REVIEW');
        $this->assertSame('NEEDS_REVIEW', $decisions['anon-001'], 'anonymous account has nothing portable');

        // oauth-only user
        $oauthUser = null;
        $rawAnalyzer = new FirebaseAuthAnalyzer;
        $oauthOnly = $rawAnalyzer->analyze([
            ['localId' => 'x', 'providerUserInfo' => [['providerId' => 'google.com']]],
        ]);
        $this->assertSame('MAPPABLE_WITH_PROVIDER_REAUTH', $oauthOnly['users'][0]['migration_decision']);
    }

    public function test_password_hash_material_is_scrubbed_even_if_payload_carries_it(): void
    {
        // Defense in depth (29F): even a payload that (incorrectly) carries
        // hash material must not survive analysis.
        $analyzer = new FirebaseAuthAnalyzer;
        $result = $analyzer->analyze([
            [
                'localId' => 'evil-user',
                'email' => 'evil@example.test',
                'passwordHash' => 'Uk5EVEVTVA==',
                'passwordSalt' => 'salt-value',
                'salt' => 'salt-value-2',
                'refreshToken' => 'token-value',
                'providerUserInfo' => [['providerId' => 'password', 'email' => 'evil@example.test']],
                'customClaims' => ['role' => 'admin'],
            ],
        ]);
        $serialized = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Uk5EVEVA', (string) $serialized);
        $this->assertStringNotContainsString('salt-value', (string) $serialized);
        $this->assertStringNotContainsString('token-value', (string) $serialized);
        $this->assertSame(['role' => 'string'], $result['claims_shapes'][0]['shape'], 'claims reduced to SHAPE — values never stored');
    }

    // ── 29G — Firebase Storage ───────────────────────────────────────────

    public function test_storage_inventory_and_strategies(): void
    {
        $inventory = $this->adapterInventory();
        $storage = $inventory['firebase']['storage'];

        $this->assertTrue($storage['present']);
        $this->assertSame('temm-dogfood-sandbox.appspot.com', $storage['bucket']);
        $this->assertSame(5, $storage['object_count']);
        $this->assertSame(4, count($storage['content_types']), 'image/png, pdf, text, octet-stream → 4 distinct types');
        $this->assertArrayHasKey('application/pdf', $storage['content_types']);

        $names = array_column($storage['objects'], 'name');
        $this->assertContains('الملفات/عربي/readme.txt', $names, 'Arabic object names survive inventory');

        $strategies = array_column($storage['objects'], 'strategy', 'name');
        $this->assertSame('STREAM_TO_PLATFORM_STORAGE', $strategies['avatars/u001.png']);
        $this->assertSame('PRESERVE_METADATA', $strategies['invoices/2026/o001.pdf'], 'custom metadata → PRESERVE_METADATA');
        $this->assertSame(1, $storage['strategies']['SKIP'], 'zero-byte object classified SKIP');
    }

    public function test_storage_strategy_decision_rules(): void
    {
        $analyzer = new FirebaseStorageAnalyzer;
        $this->assertSame('STREAM_TO_PLATFORM_STORAGE', $analyzer->decideStrategy(1024, 'abc='));
        $this->assertSame('SKIP', $analyzer->decideStrategy(0, 'abc='));
        $this->assertSame('NEEDS_REVIEW', $analyzer->decideStrategy(1024, ''), 'no checksum → fidelity unverifiable');
    }

    // ── 29H — Cloud Functions ────────────────────────────────────────────

    public function test_functions_inventory_and_classification(): void
    {
        $inventory = $this->adapterInventory();
        $functions = $inventory['firebase']['functions'];

        $this->assertTrue($functions['available']);
        $this->assertSame(4, $functions['count']);
        $classes = array_column($functions['functions'], 'class', 'short_name');
        $this->assertSame('HTTP', $classes['apiHello']);
        $this->assertSame('FIRESTORE_TRIGGERED', $classes['onOrderWrite']);
        $this->assertSame('SCHEDULED', $classes['cleanupUploads']);
        $this->assertSame('AUTH_TRIGGERED', $classes['onUserCreate']);
    }

    public function test_functions_unavailable_is_reported_honestly(): void
    {
        $analyzer = new FirebaseFunctionsAnalyzer;
        $result = $analyzer->analyze([], false);
        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['count']);
    }

    public function test_no_1to1_function_mapping_is_claimed(): void
    {
        $inventory = $this->adapterInventory();
        foreach ($inventory['firebase']['functions']['functions'] as $function) {
            $this->assertStringContainsString('No 1:1 platform mapping', $function['notes'], '29H — conversion needs review, never automatic parity');
        }
    }
}
