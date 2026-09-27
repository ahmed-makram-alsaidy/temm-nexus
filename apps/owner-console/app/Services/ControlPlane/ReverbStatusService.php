<?php

namespace App\Services\ControlPlane;

use App\Models\Project;

/**
 * Reverb configuration status for a project checkout.
 * Reports Configured / Not configured / Local only — never secret values.
 * Live connectivity is proven separately by the WebSocket handshake test (18H).
 */
class ReverbStatusService
{
    public function __construct(protected Project $project) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    public function status(): array
    {
        $base = ControlPlanePaths::projectDir($this->project->slug);
        $out = [
            'installed' => is_file($base.'/config/reverb.php'),
            'app_key' => 'Not configured',
            'host' => null,
            'port' => null,
            'scheme' => null,
            'server_running' => false,
        ];
        $envFile = $base.'/.env';
        if (is_file($envFile)) {
            $env = [];
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
            $key = $env['REVERB_APP_KEY'] ?? '';
            $out['app_key'] = ($key === '' || $key === 'local-key') ? 'Not configured' : 'Configured';
            $out['host'] = $env['REVERB_HOST'] ?? null;
            $out['port'] = $env['REVERB_PORT'] ?? null;
            $out['scheme'] = $env['REVERB_SCHEME'] ?? null;
        }

        return $out;
    }
}
