<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29H — Cloud Functions inventory and trigger classification.
 *
 * Functions are inventoried WHERE SAFELY DISCOVERABLE (Cloud Functions API
 * available to the credential). Classification is honest — Firebase
 * functions do NOT map 1:1 to platform functions; the inventory exists so
 * the migration plan can surface what has to be re-hosted, and (later, via
 * the AI Copilot) so conversion guidance can be generated for review.
 *
 * Classes: HTTP, CALLABLE (HTTPS function following the callable convention),
 * FIRESTORE_TRIGGERED, STORAGE_TRIGGERED, AUTH_TRIGGERED, SCHEDULED,
 * PUBSUB_TRIGGERED, OTHER_TRIGGERED.
 */
class FirebaseFunctionsAnalyzer
{
    /**
     * @param  list<array>  $functions  raw Cloud Functions v1 payloads
     * @return array{available: bool, count: int, classes: array<string,int>, runtimes: array<string,int>, functions: list<array>}
     */
    public function analyze(array $functions, bool $available = true): array
    {
        $classes = [];
        $runtimes = [];
        $inventory = [];

        foreach ($functions as $function) {
            $name = (string) ($function['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $class = $this->classify($function);
            $classes[$class] = ($classes[$class] ?? 0) + 1;
            $runtime = (string) ($function['runtime'] ?? 'unknown');
            $runtimes[$runtime] = ($runtimes[$runtime] ?? 0) + 1;
            $inventory[] = [
                'name' => $name,
                'short_name' => $this->shortName($name),
                'region' => $this->region($name),
                'status' => (string) ($function['status'] ?? 'UNKNOWN'),
                'class' => $class,
                'runtime' => $runtime,
                'event_type' => (string) ($function['eventTrigger']['eventType'] ?? ''),
                'event_resource' => (string) ($function['eventTrigger']['resource'] ?? ''),
                'https_url' => (string) ($function['httpsTrigger']['url'] ?? ''),
                'notes' => 'No 1:1 platform mapping is claimed (29H) — re-hosting/conversion requires review.',
            ];
        }

        return [
            'available' => $available,
            'count' => count($inventory),
            'classes' => $classes,
            'runtimes' => $runtimes,
            'functions' => $inventory,
        ];
    }

    /** Honest classification from the trigger payload. */
    public function classify(array $function): string
    {
        $triggerType = (string) ($function['triggerType'] ?? '');
        $eventType = (string) ($function['eventTrigger']['eventType'] ?? '');
        if ($triggerType === 'HTTP_TRIGGER') {
            $short = $this->shortName((string) ($function['name'] ?? ''));
            // The callable convention: server SDK's onCall wraps a callable-{hash} HTTPS function.
            if (str_starts_with($short, 'callable-') || str_contains($short, 'callable-')) {
                return 'CALLABLE';
            }

            return 'HTTP';
        }
        if (str_contains($eventType, 'cloud.firestore')) {
            return 'FIRESTORE_TRIGGERED';
        }
        if (str_contains($eventType, 'firebase.storage') || str_contains($eventType, 'storage')) {
            return 'STORAGE_TRIGGERED';
        }
        if (str_contains($eventType, 'firebase.auth')) {
            return 'AUTH_TRIGGERED';
        }
        if (str_contains($eventType, 'pubsub') && (($function['labels']['deployment-scheduled'] ?? '') === 'true' || isset($function['schedule']))) {
            return 'SCHEDULED';
        }
        if (str_contains($eventType, 'pubsub')) {
            return 'PUBSUB_TRIGGERED';
        }

        return 'OTHER_TRIGGERED';
    }

    protected function shortName(string $fullName): string
    {
        $segments = explode('/', $fullName);

        return (string) end($segments);
    }

    protected function region(string $fullName): string
    {
        foreach (explode('/', $fullName) as $i => $segment) {
            if ($segment === 'locations') {
                return explode('/', $fullName)[$i + 1] ?? '';
            }
        }

        return '';
    }
}
