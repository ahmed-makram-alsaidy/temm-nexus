<?php

namespace Tests\Feature\Phase24;

use App\Models\ProjectSecret;
use App\Services\ControlPlane\SecretVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24F — Secrets Vault: encryption, masking, rotation, RBAC, leak prevention. */
class SecretsVaultTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    protected function secret(): ProjectSecret
    {
        return SecretVaultService::createSecret($this->projectA, 'PAYMENT_GATEWAY_KEY', 'sk_live_SUPERSECRETVALUE123', [
            'category' => 'payment', 'description' => 'gateway key',
        ]);
    }

    public function test_value_is_encrypted_at_rest(): void
    {
        $secret = $this->secret();

        $raw = \DB::table('project_secrets')->where('id', $secret->id)->value('value');
        $this->assertStringNotContainsString('SUPERSECRET', $raw, 'ciphertext must not contain the plaintext');

        $decrypted = $secret->fresh()->value;
        $this->assertSame('sk_live_SUPERSECRETVALUE123', $decrypted);
    }

    public function test_masking_never_shows_the_value(): void
    {
        $value = 'sk_live_SUPERSECRETVALUE123';
        $this->assertSame(substr($value, 0, 4).'…'.substr($value, -4), SecretVaultService::mask($value));
        $this->assertSame('********', SecretVaultService::mask('short'));
        $this->assertSame('********', SecretVaultService::mask(null));
    }

    public function test_listing_row_never_contains_value(): void
    {
        $row = SecretVaultService::listingRow($this->secret());
        $this->assertArrayNotHasKey('value', $row);
        $this->assertSame('payment', $row['category']);
        $this->assertSame(1, $row['version']);
    }

    public function test_rotation_tracks_versions(): void
    {
        $secret = $this->secret();
        SecretVaultService::rotate($secret, 'sk_live_ROTATED_VALUE_456');

        $fresh = $secret->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame(1, $fresh->rotation_meta['previous_version']);
        $this->assertSame('sk_live_ROTATED_VALUE_456', $fresh->value);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'SECRET_ROTATED', 'project_id' => $this->projectA->id]);
    }

    public function test_categories_are_enforced(): void
    {
        $this->expectException(HttpException::class);
        SecretVaultService::createSecret($this->projectA, 'WEIRD_ONE', 'value', ['category' => 'not-a-category']);
    }

    public function test_reveal_requires_manage_permission_and_audits(): void
    {
        $secret = $this->secret();

        $observer = \App\Models\User::factory()->create(['is_admin' => false, 'cp_role' => 'observer']);
        $this->actingAs($observer);
        try {
            SecretVaultService::reveal($secret);
            $this->fail('observer must not reveal');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->admin);
        $this->assertSame('sk_live_SUPERSECRETVALUE123', SecretVaultService::reveal($secret));
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'SECRET_REVEALED', 'project_id' => $this->projectA->id]);
    }

    public function test_leak_scan_detects_nothing_when_clean(): void
    {
        $this->secret();
        $result = SecretVaultService::leakScan($this->projectA);
        $this->assertSame(1, $result['scanned']);
        $this->assertSame(0, $result['leaks']);
    }

    public function test_leak_scan_finds_leaks_in_audit_metadata(): void
    {
        // Force a "leak": write the raw value into audit metadata.
        $secret = $this->secret();
        \App\Services\ControlPlane\AdminAudit::record('PROJECT_SETTINGS_UPDATED', $this->projectA, null, null, [
            'oops' => 'sk_live_SUPERSECRETVALUE123',
        ]);

        $result = SecretVaultService::leakScan($this->projectA);
        $this->assertSame(1, $result['leaks']);
        $this->assertSame('PAYMENT_GATEWAY_KEY', $result['findings'][0]['secret']);
    }
}
