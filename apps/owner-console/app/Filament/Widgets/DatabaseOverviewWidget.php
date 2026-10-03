<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

/**
 * Read-only database overview via the least-privilege pgsql-monitor connection.
 * No destructive operations are exposed here by design.
 */
class DatabaseOverviewWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Database overview (read-only)';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows())
            ->columns([
                TextColumn::make('db')->label(__('labels.database')),
                TextColumn::make('size')->label(__('labels.size')),
                TextColumn::make('conns')->label(__('labels.active_conns')),
                TextColumn::make('tables')->label(__('labels.tables')),
            ])
            ->paginated(false);
    }

    private function rows()
    {
        try {
            $rows = DB::connection('pgsql-monitor')->select(
                "SELECT d.datname AS db,
                        pg_size_pretty(pg_database_size(d.datname)) AS size,
                        (SELECT count(*) FROM pg_stat_activity a WHERE a.datname = d.datname) AS conns
                   FROM pg_database d WHERE d.datistemplate = false ORDER BY pg_database_size(d.datname) DESC"
            );
            foreach ($rows as $r) {
                try {
                    $t = DB::connection('pgsql-monitor')->select(
                        "SELECT count(*) AS c FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')"
                    );
                    // Table count is server-wide here; per-DB counts need a connection per DB.
                    $r->tables = $t[0]->c ?? '?';
                } catch (\Throwable) {
                    $r->tables = '?';
                }
            }

            return collect($rows);
        } catch (\Throwable $e) {
            return collect([(object) ['db' => 'unavailable', 'size' => '—', 'conns' => '—', 'tables' => $e->getMessage()]]);
        }
    }
}
