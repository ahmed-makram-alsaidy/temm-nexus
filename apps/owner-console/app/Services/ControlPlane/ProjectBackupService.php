<?php

namespace App\Services\ControlPlane;

use App\Models\BackupRecord;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * UI-triggered backups using the same pg_dump mechanism as Phase 9 scripts,
 * executed from the console container (postgresql-client is in the image).
 * Dumps with the PROJECT's own role (least privilege) into /backups.
 * Restore stays a deliberate runbook operation — the UI never restores.
 */
class ProjectBackupService
{
    public function __construct(protected Project $project) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    /** @return list<array{file:string,size:int,modified:string,checksum_ok:?bool,record:?BackupRecord}> */
    public function backups(): array
    {
        $prefix = ($this->project->db_name ?? $this->project->slug).'_';
        $files = glob('/backups/'.$prefix.'*.dump') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, 50) as $file) {
            $record = BackupRecord::where('db_name', $this->project->db_name)
                ->where('log', 'like', '%'.basename($file).'%')
                ->latest('finished_at')->first();
            $sha = $file.'.sha256';
            $checksumOk = null;
            if (is_file($sha)) {
                $expected = explode(' ', trim(file_get_contents($sha)))[0] ?? '';
                $checksumOk = hash_equals($expected, hash_file('sha256', $file));
            }
            $out[] = [
                'file' => basename($file),
                'size' => filesize($file),
                'modified' => date('c', filemtime($file)),
                'checksum_ok' => $checksumOk,
                'record' => $record,
            ];
        }

        return $out;
    }

    public function lastBackup(): ?BackupRecord
    {
        return BackupRecord::where('db_name', $this->project->db_name)->latest('finished_at')->first();
    }

    public function lastVerified(): ?BackupRecord
    {
        return BackupRecord::where('db_name', $this->project->db_name)
            ->whereNotNull('verified_at')->latest('verified_at')->first();
    }

    /**
     * Run pg_dump as the project's own role. Returns the BackupRecord.
     *
     * @throws \RuntimeException on failure (message contains no secrets).
     */
    public function trigger(string $type = 'full'): BackupRecord
    {
        $p = ProjectConnectionManager::envPrefix($this->project);
        $database = env($p.'DATABASE', $this->project->db_name);
        $username = env($p.'USERNAME');
        $password = env($p.'PASSWORD');
        abort_if(! $username || ! $password, 422, 'Project database credentials are not configured.');

        $ts = gmdate('Ymd\THis\Z');
        $file = "/backups/{$database}_{$ts}.dump";
        // Phase 21B: dump from the project's RESOLVED endpoint (override
        // chain), not the raw env default — otherwise a moved database would
        // silently back up the old host. Destination stays local disk; object
        // storage is a documented follow-up (see MULTI_NODE docs).
        $ep = InfrastructureMapper::dbEndpoint($this->project);
        $cmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -Fc -f %s 2>&1',
            escapeshellarg($password),
            escapeshellarg($ep['host']),
            escapeshellarg((string) $ep['port']),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($file)
        );
        // Password is passed via env to the child only; never logged or returned.
        exec($cmd, $output, $code);
        if ($code !== 0 || ! is_file($file)) {
            throw new \RuntimeException('pg_dump failed: '.implode("\n", array_slice($output, 0, 5)));
        }
        exec(sprintf('sha256sum %s > %s', escapeshellarg($file), escapeshellarg($file.'.sha256')));

        return BackupRecord::create([
            'db_name' => $database,
            'status' => 'ok',
            'size_bytes' => filesize($file),
            'checksum' => hash_file('sha256', $file),
            'finished_at' => now(),
            'restore_test_status' => 'not_tested',
            'type' => $type,
            'location' => 'local',
            'log' => 'triggered from control plane: '.basename($file),
        ]);
    }

    /** Verify checksum + archive integrity; records verified_at. */
    public function verify(BackupRecord $record): bool
    {
        if (! preg_match('/(\S+\.dump)\s*$/', $record->log ?? '', $m)) {
            throw new \RuntimeException('No dump filename recorded for this backup.');
        }
        $file = '/backups/'.$m[1];
        abort_unless(is_file($file), 404, 'Dump file no longer present.');
        $actual = hash_file('sha256', $file);
        abort_unless(hash_equals((string) $record->checksum, $actual), 422, 'Checksum mismatch.');

        exec(sprintf('pg_restore --list %s 2>&1 | wc -l', escapeshellarg($file)), $out, $code);
        abort_if($code !== 0 || (int) trim(implode('', $out)) === 0, 422, 'Archive listing empty.');

        $record->update(['verified_at' => now(), 'log' => ($record->log ?? '').' | verified '.now()->toDateTimeString()]);

        return true;
    }

    public function stats(): array
    {
        try {
            $bytes = DB::connection('pgsql-monitor')->selectOne(
                'SELECT pg_database_size(?) AS b', [$this->project->db_name]
            )->b ?? 0;
        } catch (\Throwable) {
            $bytes = 0;
        }

        return [
            'last_backup' => $this->lastBackup(),
            'last_verified' => $this->lastVerified(),
            'db_bytes' => (int) $bytes,
            'files' => $this->backups(),
        ];
    }
}
