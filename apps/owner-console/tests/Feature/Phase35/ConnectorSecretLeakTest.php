<?php

namespace Tests\Feature\Phase35;

use App\Connectors\ExampleJson\ExampleJsonConnector;
use App\Connectors\Firebase\FirebaseConnector;
use App\Connectors\Mongodb\MongodbConnector;
use App\Connectors\Mysql\MysqlConnector;
use App\Connectors\Postgres\PostgresConnector;
use App\Connectors\Supabase\SupabaseConnector;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester;
use Tests\TestCase;

/**
 * Phase 35.5 live-verification regression: canary secrets must never leak
 * through testConnection output or exception messages.
 *
 * Found live: the connector contract battery's secret_leak check FAILED for
 * the real PostgreSQL connector — provider/guard exceptions echo connection
 * parameters (the canary was handed to every field, host included), and the
 * raw message reached ConnectorTestResult. All first-party connectors now
 * redact every credential value from error surfaces (ConnectorCredentials::
 * redactFrom), and this test pins that for the whole set.
 */
class ConnectorSecretLeakTest extends TestCase
{
    public function test_secret_leak_battery_passes_for_every_first_party_connector(): void
    {
        $connectors = [
            'postgres' => new PostgresConnector,
            'mysql' => new MysqlConnector,
            'mongodb' => new MongodbConnector,
            'firebase' => new FirebaseConnector,
            'supabase' => new SupabaseConnector,
            'example-json' => new ExampleJsonConnector,
        ];

        foreach ($connectors as $key => $connector) {
            $result = ConnectorContractTester::checkSecretLeak($connector);

            $this->assertSame(
                'PASS',
                $result['status'],
                "[{$key}] canary secret leaked through testConnection: {$result['detail']}"
            );
        }
    }

    public function test_redact_from_strips_every_credential_value_including_urlencoded(): void
    {
        $credentials = ConnectorCredentials::fromArray([
            'host' => 'canary-host.example',
            'username' => 'canary-user',
            'password' => 'CANARY-SECRET-abcdef',
        ], ['password']);

        $message = 'connection to server at "canary-host.example" failed for user "canary-user" '
            .'with password CANARY-SECRET-abcdef (dsn: canary-host.example%2Bside)';

        $redacted = $credentials->redactFrom($message);

        $this->assertStringNotContainsString('canary-host.example', $redacted);
        $this->assertStringNotContainsString('canary-user', $redacted);
        $this->assertStringNotContainsString('CANARY-SECRET-abcdef', $redacted);
        $this->assertStringNotContainsString('canary-host.example%2Bside', $redacted);
    }
}
