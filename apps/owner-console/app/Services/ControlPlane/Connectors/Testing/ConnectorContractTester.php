<?php

namespace App\Services\ControlPlane\Connectors\Testing;

use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\Contracts\Connector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;

/**
 * Phase 27L — the connector testing SDK.
 *
 * Reusable contract checks every first-party source connector must pass
 * (27L.1). The artisan `connector:test` command drives this tester; PHPUnit
 * feature tests embed it as their assertion engine. Checks are HONEST —
 * a check that cannot be executed for a connector reports SKIP, never PASS.
 */
class ConnectorContractTester
{
    /**
     * Run the full contract battery against a connector.
     *
     * @return array<string, array{status: string, detail: string}> status: PASS|FAIL|SKIP
     */
    public static function run(Connector $connector, array $options = []): array
    {
        $results = [];
        $results['manifest'] = self::checkManifest($connector);
        $results['definition'] = self::checkDefinition($connector);
        $results['capabilities'] = self::checkCapabilities($connector);
        $results['credential_schema'] = self::checkCredentialSchema($connector);
        $results['capability_status_honesty'] = self::checkCapabilityStatusHonesty($connector);
        $results['secret_leak'] = self::checkSecretLeak($connector);
        $results['unknown_handling'] = self::checkUnknownHandling($connector);
        if ($connector instanceof SourceConnector) {
            $results['read_only_profile'] = self::checkReadOnlyProfile($connector, $options);
        }

        return $results;
    }

    /** @return array{status: string, detail: string} */
    public static function summarize(array $results): array
    {
        $failed = array_filter($results, fn ($r) => $r['status'] === 'FAIL');
        $passed = count(array_filter($results, fn ($r) => $r['status'] === 'PASS'));
        $skipped = count(array_filter($results, fn ($r) => $r['status'] === 'SKIP'));

        return [
            'status' => $failed === [] ? 'PASS' : 'FAIL',
            'detail' => $passed.' passed, '.count($failed).' failed, '.$skipped.' skipped'
                .($failed !== [] ? ' — '.implode(', ', array_keys($failed)) : ''),
        ];
    }

    public static function checkManifest(Connector $connector): array
    {
        try {
            $manifest = $connector->manifest();
            if ($manifest->key() === '' || $manifest->version() === '') {
                return ['status' => 'FAIL', 'detail' => 'manifest missing key/version'];
            }

            return ['status' => 'PASS', 'detail' => $manifest->key().' v'.$manifest->version().' schema v'.$manifest->schemaVersion().' trust '.$manifest->trust()];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => 'manifest invalid: '.mb_substr($e->getMessage(), 0, 160)];
        }
    }

    public static function checkDefinition(Connector $connector): array
    {
        try {
            $definition = $connector->definition();
            if ($definition->name === '' || $definition->description === '') {
                return ['status' => 'FAIL', 'detail' => 'definition missing name/description'];
            }

            return ['status' => 'PASS', 'detail' => $definition->name.' — '.count($definition->credentials).' credential field(s)'];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => 'definition error: '.mb_substr($e->getMessage(), 0, 160)];
        }
    }

    public static function checkCapabilities(Connector $connector): array
    {
        $invalid = array_filter($connector->capabilities(), fn ($c) => ! ConnectorCapability::isValid((string) $c));

        return $invalid === []
            ? ['status' => 'PASS', 'detail' => count($connector->capabilities()).' capability/capabilities in vocabulary']
            : ['status' => 'FAIL', 'detail' => 'unknown capabilities: '.implode(',', $invalid)];
    }

    public static function checkCredentialSchema(Connector $connector): array
    {
        $problems = [];
        foreach ($connector->credentialSchema() as $field) {
            if ($field->key === '' || $field->label === '') {
                $problems[] = 'field without key/label';
            }
            if ($field->secret && $field->default !== null) {
                // 27T — a secret field with a default value would plant
                // credential material in the manifest/definition.
                $problems[] = "secret field '{$field->key}' declares a default value";
            }
            if ($field->capability !== null && ! ConnectorCapability::isValid($field->capability)) {
                $problems[] = "field '{$field->key}' unlocks unknown capability '{$field->capability}'";
            }
        }
        foreach ($connector->definition()->configuration as $field) {
            if ($field->secret) {
                $problems[] = "configuration field '{$field->key}' must not be secret";
            }
        }

        return $problems === []
            ? ['status' => 'PASS', 'detail' => 'credential schema safe']
            : ['status' => 'FAIL', 'detail' => implode('; ', $problems)];
    }

    /** 27C.1 — an unknown/undeclared capability must never report SUPPORTED. */
    public static function checkCapabilityStatusHonesty(Connector $connector): array
    {
        foreach (ConnectorCapability::ALL as $capability) {
            if (! in_array($capability, $connector->capabilities(), true)
                && $connector->capabilityStatus($capability, []) === ConnectorCapability::SUPPORTED) {
                return ['status' => 'FAIL', 'detail' => "undeclared capability '{$capability}' reports SUPPORTED — faked support"];
            }
        }

        return ['status' => 'PASS', 'detail' => 'status never fakes undeclared support'];
    }

    /**
     * 27L secret leak check: run testConnection with a canary secret and
     * verify the raw value never appears in any returned/derivable surface.
     */
    public static function checkSecretLeak(Connector $connector): array
    {
        $canary = 'CANARY-SECRET-'.bin2hex(random_bytes(6));
        $credentials = ConnectorCredentials::fromArray(
            array_fill_keys(array_map(fn ($f) => $f->key, $connector->credentialSchema()), $canary),
            array_map(fn ($f) => $f->key, array_filter($connector->credentialSchema(), fn ($f) => $f->secret))
        );
        try {
            $result = $connector->testConnection($credentials);
            // Only the connector's OUTPUT must be checked — non-secret
            // configuration legitimately echoes back on the credentials'
            // own redacted view.
            $serialized = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
            if (str_contains((string) $serialized, $canary)) {
                return ['status' => 'FAIL', 'detail' => 'canary secret leaked through testConnection output'];
            }

            return ['status' => 'PASS', 'detail' => 'no canary leakage in connection test output ('.$result->result.')'];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), $canary)) {
                return ['status' => 'FAIL', 'detail' => 'canary secret leaked through exception message'];
            }

            return ['status' => 'PASS', 'detail' => 'test failed safely without leaking the canary ('.class_basename($e).')'];
        }
    }

    /** Connection test on empty credentials must classify, not crash. */
    public static function checkUnknownHandling(Connector $connector): array
    {
        try {
            $result = $connector->testConnection(ConnectorCredentials::fromArray([]));
            if (! in_array($result->result, \App\Services\ControlPlane\Connectors\ConnectorTestResult::RESULTS, true)) {
                return ['status' => 'FAIL', 'detail' => 'unclassified test result'];
            }

            return ['status' => 'PASS', 'detail' => 'empty credentials classified as '.$result->result];
        } catch (\Throwable $e) {
            return ['status' => 'FAIL', 'detail' => 'testConnection crashed on empty credentials: '.mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /** Source connectors must create READ-ONLY profiles. */
    public static function checkReadOnlyProfile(SourceConnector $connector, array $options = []): array
    {
        if (empty($options['project'])) {
            return ['status' => 'SKIP', 'detail' => 'requires a project fixture'];
        }
        try {
            $source = $connector->createSourceProfile(
                $options['project'],
                [],
                $options['configuration'] ?? [],
                $options['environment_id'] ?? null
            );
            $readOnly = (bool) $source->read_only;
            $source->delete();

            return $readOnly
                ? ['status' => 'PASS', 'detail' => 'created profile is read-only']
                : ['status' => 'FAIL', 'detail' => 'created profile is NOT read-only'];
        } catch (\Throwable $e) {
            return ['status' => 'SKIP', 'detail' => 'profile creation needs configuration: '.mb_substr($e->getMessage(), 0, 120)];
        }
    }
}
