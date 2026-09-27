<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Read-only PostgreSQL diagnostics for the selected project database.
 * Uses the project connection for its own DB and the monitor connection
 * for server-wide counters. No superuser credentials involved.
 */
class ProjectDbHealth extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Health';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'Health'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('db-health'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $sections = [];
        try {
            $p = $this->project();
            $conn = ProjectConnectionManager::connection($p);
            $version = DB::connection($conn)->selectOne('SELECT version() AS v')->v;
            $sizeRow = DB::connection('pgsql-monitor')->selectOne(
                'SELECT pg_size_pretty(pg_database_size(?)) AS size, (SELECT count(*) FROM pg_stat_activity WHERE datname = ?) AS conns',
                [$p->db_name, $p->db_name]
            );
            $maxConns = DB::connection('pgsql-monitor')->selectOne('SHOW max_connections');
            $uptime = DB::connection('pgsql-monitor')->selectOne("SELECT date_trunc('second', now() - pg_postmaster_start_time()) AS up");

            $largest = DB::connection($conn)->select(
                "SELECT relname AS table, pg_size_pretty(pg_total_relation_size(relid)) AS size
                   FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10"
            );
            $indexStats = DB::connection($conn)->select(
                "SELECT relname AS table, indexrelname AS index, idx_scan AS scans, pg_size_pretty(pg_relation_size(indexrelid)) AS size
                   FROM pg_stat_user_indexes ORDER BY idx_scan ASC LIMIT 10"
            );
            try {
                $slow = DB::connection($conn)->select(
                    'SELECT left(query, 120) AS query, calls, round(mean_exec_time::numeric, 1) AS mean_ms
                       FROM pg_stat_statements ORDER BY mean_exec_time DESC LIMIT 10'
                );
            } catch (\Throwable) {
                $slow = null;
            }

            $sections[] = Grid::make(3)->schema([
                Section::make('Database')->schema([
                    TextEntry::make('db')->state($p->db_name)->copyable(),
                    TextEntry::make('size')->state($sizeRow->size ?? '—'),
                    TextEntry::make('conns')->state(($sizeRow->conns ?? '—').' / '.($maxConns->max_connections ?? '?')),
                ]),
                Section::make('Server')->schema([
                    TextEntry::make('pg')->state($this->shortVersion($version)),
                    TextEntry::make('uptime')->state((string) ($up->up ?? '—')),
                    TextEntry::make('slow_src')->label('Slow queries')->state($slow === null ? 'pg_stat_statements unavailable' : count($slow).' slowest shown'),
                ]),
                Section::make('Tables')->schema([
                    TextEntry::make('count')->state(count($largest).' largest shown below'),
                ]),
            ]);
            $sections[] = Grid::make(2)->schema([
                Section::make('Largest tables')->schema([
                    RepeatableEntry::make('largest')->state(array_map(fn ($r) => (array) $r, $largest))
                        ->schema([TextEntry::make('table')->badge(), TextEntry::make('size')])->contained(false),
                ]),
                Section::make('Least-scanned indexes')->schema([
                    RepeatableEntry::make('idx')->state(array_map(fn ($r) => (array) $r, $indexStats))
                        ->schema([TextEntry::make('index'), TextEntry::make('scans'), TextEntry::make('size')])->contained(false),
                ]),
            ]);
            if ($slow !== null && $slow !== []) {
                $sections[] = Section::make('Slowest queries (mean)')->schema([
                    RepeatableEntry::make('slow')->state(array_map(fn ($r) => (array) $r, $slow))
                        ->schema([TextEntry::make('query'), TextEntry::make('calls'), TextEntry::make('mean_ms')->label('Mean ms')])
                        ->contained(false),
                ]);
            }
        } catch (\Throwable $e) {
            $sections[] = static::connectionError($e);
        }

        return $schema->components($sections);
    }

    protected function shortVersion(string $v): string
    {
        return explode(',', $v)[0] ?? $v;
    }
}
