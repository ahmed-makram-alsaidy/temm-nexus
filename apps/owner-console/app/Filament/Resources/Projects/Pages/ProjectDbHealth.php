<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only PostgreSQL diagnostics for the selected project database.
 * Uses the canonical environment connection for reachability and diagnostics.
 * A missing monitor account cannot mark a reachable project DB as unreachable.
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
        return __('labels.health');
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => __('labels.tables'), __('labels.health')];
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
            $database = config("database.connections.{$conn}.database");
            $version = DB::connection($conn)->selectOne('SELECT version() AS v')->v;
            $sections[] = Section::make(__('connections.project_database'))->schema([
                Html::make('<p>'.e(__('connections.connected')).'</p><p>'.e(__('connections.source_separate')).'</p>'),
            ]);
            $sizeRow = DB::connection($conn)->selectOne(
                'SELECT pg_size_pretty(pg_database_size(?)) AS size, (SELECT count(*) FROM pg_stat_activity WHERE datname = ?) AS conns',
                [$database, $database]
            );
            $maxConns = DB::connection($conn)->selectOne('SHOW max_connections');
            $uptime = DB::connection($conn)->selectOne("SELECT date_trunc('second', now() - pg_postmaster_start_time()) AS up");

            $largest = DB::connection($conn)->select(
                'SELECT relname AS table, pg_size_pretty(pg_total_relation_size(relid)) AS size
                   FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10'
            );
            $indexStats = DB::connection($conn)->select(
                'SELECT relname AS table, indexrelname AS index, idx_scan AS scans, pg_size_pretty(pg_relation_size(indexrelid)) AS size
                   FROM pg_stat_user_indexes ORDER BY idx_scan ASC LIMIT 10'
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
                Section::make(__('labels.dbh_database'))->schema([
                    TextEntry::make('db')->label(__('labels.th_db'))->state($database)->copyable()->html()
                        ->formatStateUsing(fn (string $state): string => '<bdi dir="ltr">'.e($state).'</bdi>'),
                    TextEntry::make('size')->label(__('labels.size'))->state($sizeRow->size ?? '—'),
                    TextEntry::make('conns')->label(__('labels.dbh_conns'))->state(($sizeRow->conns ?? '—').' / '.($maxConns->max_connections ?? '?')),
                ]),
                Section::make(__('labels.dbh_server'))->schema([
                    TextEntry::make('pg')->label(__('labels.dbh_pg_version'))->state($this->shortVersion($version)),
                    TextEntry::make('uptime')->label(__('labels.dbh_uptime'))->state((string) ($uptime->up ?? '—')),
                    TextEntry::make('slow_src')->label(__('labels.slow_queries'))->state($slow === null ? __('labels.dbh_slow_unavailable') : __('labels.dbh_slow_count', ['count' => count($slow)])),
                ]),
                Section::make(__('labels.dbh_tables'))->schema([
                    TextEntry::make('count')->label(__('labels.dbh_largest_count'))->state(__('labels.dbh_largest_shown', ['count' => count($largest)])),
                ]),
            ]);
            $sections[] = Grid::make(2)->schema([
                Section::make(__('labels.dbh_largest'))->schema([
                    RepeatableEntry::make('largest')->label(__('labels.dbh_largest'))->state(array_map(fn ($r) => (array) $r, $largest))
                        ->schema([TextEntry::make('table')->label(__('labels.th_table'))->badge(), TextEntry::make('size')->label(__('labels.size'))])->contained(false),
                ])->description(__('labels.dbh_largest_hint')),
                Section::make(__('labels.dbh_least_scanned'))->schema([
                    RepeatableEntry::make('idx')->label(__('labels.dbh_least_scanned'))->state(array_map(fn ($r) => (array) $r, $indexStats))
                        ->schema([TextEntry::make('index')->label(__('labels.dbh_index')), TextEntry::make('scans')->label(__('labels.dbh_scans')), TextEntry::make('size')->label(__('labels.size'))])->contained(false),
                ])->description(__('labels.dbh_least_scanned_hint')),
            ]);
            if ($slow !== null && $slow !== []) {
                $sections[] = Section::make(__('labels.dbh_slowest'))->schema([
                    RepeatableEntry::make('slow')->label(__('labels.slow_queries'))->state(array_map(fn ($r) => (array) $r, $slow))
                        ->schema([TextEntry::make('query')->label(__('labels.dbh_query'))->copyable(), TextEntry::make('calls')->label(__('labels.dbh_calls')), TextEntry::make('mean_ms')->label(__('labels.mean_ms'))])
                        ->contained(false),
                ])->description(__('labels.dbh_slowest_hint'));
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
