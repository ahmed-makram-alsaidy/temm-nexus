<?php

namespace App\Services\ControlPlane;

use App\Models\Project;

/**
 * Reads a project's Laravel logs from the repo checkout:
 *   <repo>/projects/<slug>/storage/logs/laravel-*.log
 * Special slug 'owner-console' reads the console's own logs.
 * Output is sanitized, paginated in PHP (files are small locally), and
 * searchable/filterable by severity. Never shells out.
 */
class ProjectLogReader
{
    public function __construct(protected Project|string $project, protected string $repoRoot) {}

    public static function for(Project|string $project): self
    {
        return new self($project, ControlPlanePaths::repoRoot());
    }

    public function slug(): string
    {
        return $this->project instanceof Project ? $this->project->slug : $this->project;
    }

    public function logDir(): string
    {
        return $this->slug() === 'owner-console'
            ? base_path('storage/logs')
            : $this->repoRoot.'/projects/'.$this->slug().'/storage/logs';
    }

    /** @return list<string> newest-first log files */
    public function files(): array
    {
        $dir = $this->logDir();
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/laravel-*.log') ?: [];

        rsort($files);

        return array_slice($files, 0, 14);
    }

    /**
     * @return array{entries:list<array{datetime:?string,severity:string,message:string,raw:string}>,total:int,page:int,per_page:int}
     */
    public function entries(?string $file = null, ?string $severity = null, ?string $search = null, int $page = 1, int $perPage = 50): array
    {
        $file ??= ($this->files()[0] ?? null);
        $entries = [];
        if ($file && str_starts_with(realpath($file) ?: '', realpath($this->logDir()) ?: "\0")) {
            $entries = $this->parse($file);
        }

        if ($severity) {
            $entries = array_values(array_filter($entries, fn ($e) => strtoupper($e['severity']) === strtoupper($severity)));
        }
        if ($search) {
            $entries = array_values(array_filter($entries, fn ($e) => stripos($e['message'], $search) !== false));
        }

        $total = count($entries);

        return [
            'entries' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return list<array{datetime:?string,severity:string,message:string,raw:string}> */
    protected function parse(string $file): array
    {
        $entries = [];
        $current = null;
        $handle = fopen($file, 'r');
        if (! $handle) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            // Laravel daily format: [2026-09-17 10:00:00] local.ERROR: message {context} {"exception":...}
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+\S+\.([A-Z]+):\s?(.*)$/', rtrim($line), $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = ['datetime' => $m[1], 'severity' => $m[2], 'message' => LogSanitizer::sanitize($m[3]), 'raw' => ''];
            } elseif ($current) {
                $current['message'] .= "\n".LogSanitizer::sanitize(rtrim($line));
            }
        }
        if ($current) {
            $entries[] = $current;
        }
        fclose($handle);

        return array_reverse($entries);
    }

    /** @return array<string,int> severity => count for the newest file */
    public function severitySummary(): array
    {
        $summary = [];
        foreach ($this->entries(perPage: 5000)['entries'] as $e) {
            $summary[$e['severity']] = ($summary[$e['severity']] ?? 0) + 1;
        }

        return $summary;
    }
}
