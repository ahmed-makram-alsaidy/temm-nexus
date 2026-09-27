<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Reads Pulse data from the PROJECT database (Pulse tables are migrated per app).
 * Returns empty states when Pulse was never installed or never ran.
 */
class PulseReader
{
    public function __construct(protected Project $project) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    public function tablesPresent(): bool
    {
        try {
            $conn = ProjectConnectionManager::connection($this->project);

            return DB::connection($conn)->getSchemaBuilder()->hasTable('pulse_entries');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,int> type => entry count */
    public function entryCounts(): array
    {
        if (! $this->tablesPresent()) {
            return [];
        }
        try {
            $conn = ProjectConnectionManager::connection($this->project);
            $rows = DB::connection($conn)->table('pulse_entries')
                ->selectRaw('type, count(*) AS c')->groupBy('type')->orderByDesc('c')->limit(20)->get();

            return $rows->pluck('c', 'type')->map(fn ($c) => (int) $c)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{type:string,key:string,value:string,timestamp:string}> */
    public function recent(string $type, int $limit = 25): array
    {
        if (! $this->tablesPresent()) {
            return [];
        }
        try {
            $conn = ProjectConnectionManager::connection($this->project);

            return DB::connection($conn)->table('pulse_entries')
                ->select(['type', 'key', 'value', 'timestamp'])
                ->where('type', $type)->orderByDesc('timestamp')->limit($limit)->get()
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
