<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Redis;

/**
 * Queue summary: pending depth per project queue prefix + link-out to Horizon.
 * Read-only (LLEN/SCAN); never flushes.
 */
class QueueSummaryWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Queues (use Horizon for operations)';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('queue')->label(__('labels.queue_key')),
                TextColumn::make('pending')->label(__('labels.pending')),
            ])
            ->paginated(false);
    }

    /**
     * @return array<int, array{queue: string, pending: int|string}>
     */
    private function rows(): array
    {
        try {
            $keys = Redis::connection()->command('KEYS', ['*:queues:*']);
            $rows = [];
            foreach (array_slice((array) $keys, 0, 50) as $k) {
                $rows[] = ['queue' => (string) $k, 'pending' => Redis::connection()->llen($k)];
            }
            if ($rows === []) {
                $rows[] = ['queue' => '(empty — no project queues yet)', 'pending' => 0];
            }

            return $rows;
        } catch (\Throwable $e) {
            return [['queue' => 'redis unavailable', 'pending' => '—']];
        }
    }
}
