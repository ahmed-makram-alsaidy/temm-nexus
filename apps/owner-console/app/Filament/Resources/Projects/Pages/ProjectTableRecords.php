<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectRecord;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use App\Services\ControlPlane\ProtectedTables;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class ProjectTableRecords extends Page implements HasTable
{
    use HasProjectContext;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.projects.pages.generic-table';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    #[Url(as: 'table')]
    public ?string $tableName = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->tableName ??= request()->query('table');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Table Editor';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Database', 'Tables'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--records'])->components([
            $this->subnavSection('database'),
            Grid::make(12)->extraAttributes(['class' => 'cp-dbwork'])->schema([
                Html::make($this->explorerHtml())->columnSpan(3),
                EmbeddedTable::make()->columnSpan(9),
            ]),
        ]);
    }

    protected function explorerHtml(): string
    {
        $p = $this->project();
        try {
            $objects = $this->explorer()->tables();
        } catch (\Throwable) {
            $objects = null;
        }

        $h = '<aside class="cp-dbwork__side" aria-label="Database objects" x-data="{ q: \'\' }">';
        $h .= '<div class="cp-dbwork__title">Database</div>';
        $h .= '<input class="cp-dbwork__search" type="search" placeholder="Search objects" aria-label="Search objects" x-model="q">';
        if ($objects === null) {
            $h .= '<p class="cp-dbwork__hint">Object catalog unavailable — check project connectivity.</p>';
        } else {
            $tables = array_values(array_filter($objects, fn ($t) => ($t['type'] ?? '') === 'table'));
            $views = array_values(array_filter($objects, fn ($t) => ($t['type'] ?? '') !== 'table'));
            $h .= $this->objectGroup('Tables', $tables, $p);
            $h .= $this->objectGroup('Views', $views, $p);
        }

        return $h.'</aside>';
    }

    /** @param list<array{name:string,type:string,rows:int|null}> $items */
    protected function objectGroup(string $label, array $items, Project $p): string
    {
        $h = '<details class="cp-dbwork__group" open><summary>'.e($label).' ('.count($items).')</summary>';
        if ($items === []) {
            $h .= '<p class="cp-dbwork__hint" x-show="q === \'\'">None.</p>';
        }
        foreach ($items as $t) {
            $name = (string) ($t['name'] ?? '');
            $js = strtolower($name);
            $active = $this->tableName === $name;
            $rows = $t['rows'] ?? null;
            $rows = is_numeric($rows) && (int) $rows >= 0 ? (int) $rows : null;
            $h .= '<a class="cp-dbwork__item'.($active ? ' is-active' : '').'"'
                .' x-show="q === \'\' || \''.e($js).'\'.includes(q.toLowerCase())"'
                .($active ? ' aria-current="true"' : '')
                .' href="'.e(static::projectUrl($p, 'records', ['table' => $name])).'" title="'.e($name).'">'
                .'<span class="cp-dbwork__name">'.e($name).'</span>'
                .($rows !== null ? '<span class="cp-dbwork__rows">'.number_format((int) $rows).'</span>' : '')
                .'</a>';
        }

        return $h.'</details>';
    }

    protected function explorerOrFail(): ?ProjectDatabaseExplorer
    {
        try {
            return $this->explorer();
        } catch (\Throwable) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        $explorer = $this->explorerOrFail();
        $tableName = $this->tableName ? $explorer?->assertTable($this->tableName) : null;

        if (! $explorer || ! $tableName) {
            return $this->emptyTable(
                $table,
                $this->tableName ? 'Records unavailable' : 'No table selected',
                $this->tableName
                    ? 'The selected table could not be loaded. Check project connectivity or choose another table from Browse tables.'
                    : 'Use Browse tables above to choose a table. Records and available actions will appear here.'
            );
        }

        $columns = $explorer->columns($tableName);
        $visible = ProtectedTables::visibleColumns(array_column($columns, 'name'));
        $pk = $explorer->primaryKey($tableName);
        $model = ProjectRecord::onTable($explorer->connectionName(), $tableName, $pk);

        $tableColumns = [];
        foreach ($columns as $col) {
            if (! in_array($col['name'], $visible, true)) {
                continue;
            }
            $c = TextColumn::make($col['name'])->label($col['name'])->limit(60);
            if (in_array($col['name'], $explorer->searchableColumns($tableName), true)) {
                $c = $c->searchable();
            }
            if ($col['name'] === $pk || preg_match('/^(id|created_at|updated_at|.*_at|amount|total|price|quantity)$/i', $col['name'])) {
                $c = $c->sortable();
            }
            if (preg_match('/bool/i', $col['type'])) {
                $c = $c->formatStateUsing(fn ($state) => $state ? 'true' : 'false')->badge()->color(fn ($state) => $state ? 'success' : 'gray');
            }
            if ($col['name'] !== $pk) {
                $c = $c->toggleable();
            }
            $tableColumns[] = $c;
        }

        $readOnly = ProtectedTables::isReadOnly($tableName);
        $canWrite = ! $readOnly && CpAccess::allows(auth()->user(), 'database.write');
        $identity = $tableName
            ? 'public.'.$tableName.' · '.($readOnly ? 'Protected — read-only' : ($canWrite ? 'Write access' : 'Read-only access')).' · Secret columns hidden'
            : 'Choose a table from the object explorer.';

        $table = $table
            ->heading($tableName ?: 'Records')
            ->description($identity)
            ->query(fn () => $model->newQuery()->select($visible === [] ? [$pk ?? 'id'] : $visible))
            ->columns($tableColumns)
            ->recordActions($canWrite ? [
                Action::make('edit_record')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->slideOver()
                    ->schema(fn ($record) => $this->recordForm($columns, $pk))
                    ->fillForm(fn ($record) => $this->recordAttributes($record, $columns, $pk))
                    ->action(function (array $data, $record) use ($tableName, $pk) {
                        $payload = $this->formPayload($data, $columns, $pk, false);
                        $this->explorer()->update($tableName, $record->getKey(), $payload);
                        $this->audit('RECORD_UPDATED', $tableName, $record->getKey(), ['fields' => array_keys($payload)]);
                        Notification::make()->title('Record updated')->success()->send();
                    }),
                Action::make('delete_record')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this record?')
                    ->modalDescription('Single-row delete only. This is audit logged.')
                    ->action(function ($record) use ($tableName) {
                        $this->explorer()->delete($tableName, $record->getKey());
                        $this->audit('RECORD_DELETED', $tableName, $record->getKey());
                        Notification::make()->title('Record deleted')->success()->send();
                    }),
            ] : [])
            ->headerActions(array_merge([
                Action::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(route('control-plane.csv-export', ['project' => $this->project()->id, 'table' => $tableName]), true),
            ], $canWrite ? [
                Action::make('create_record')
                    ->label('New record')
                    ->slideOver()
                    ->schema($this->recordForm($columns, $pk, true))
                    ->action(function (array $data) use ($tableName, $pk) {
                        $id = $this->explorer()->insert($tableName, $this->formPayload($data, $columns, $pk, true));
                        $this->audit('RECORD_CREATED', $tableName, $id);
                        Notification::make()->title('Record created')->success()->send();
                    }),
                Action::make('import_csv')
                    ->label('Import CSV')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->slideOver()
                    ->schema([
                        FileUpload::make('file')
                            ->label('CSV file (max 1000 rows, headers must match visible columns)')
                            ->acceptedFileTypes(['text/csv', 'text/plain', '.csv'])
                            ->maxSize(5120)
                            ->required(),
                    ])
                    ->action(function (array $data) use ($tableName, $visible) {
                        $path = storage_path('app/private/'.$data['file']);
                        abort_unless(is_file($path), 422, 'Upload failed.');
                        $handle = fopen($path, 'r');
                        $headers = array_map('trim', (array) fgetcsv($handle));
                        abort_if($headers === [], 422, 'Empty CSV.');
                        foreach ($headers as $h) {
                            abort_unless(in_array($h, $visible, true), 422, "Unknown column: {$h}");
                        }
                        $rows = [];
                        while (($line = fgetcsv($handle)) !== false && count($rows) < 1000) {
                            if (count(array_filter($line, fn ($v) => $v !== null && $v !== '')) === 0) {
                                continue;
                            }
                            $rows[] = array_combine($headers, array_slice(array_pad($line, count($headers), null), 0, count($headers)));
                        }
                        fclose($handle);
                        @unlink($path);
                        abort_if($rows === [], 422, 'No data rows.');
                        $explorer = $this->explorer();
                        $conn = $explorer->connectionName();
                        $inserted = 0;
                        foreach (array_chunk($rows, 200) as $chunk) {
                            $clean = array_map(fn ($r) => array_map(fn ($v) => $v === '' ? null : $v, $r), $chunk);
                            DB::connection($conn)->table($tableName)->insert($clean);
                            $inserted += count($clean);
                        }
                        $this->audit('RECORDS_IMPORTED', $tableName, null, ['rows' => $inserted]);
                        Notification::make()->title("Imported {$inserted} rows")->success()->send();
                    }),
            ] : []))
            ->toolbarActions($canWrite ? [
                // Bulk group: the selection toolbar (and its danger styling)
                // only appears once one or more rows are selected (Phase 20.7).
                BulkActionGroup::make([
                    BulkAction::make('bulk_delete')
                        ->label('Delete selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected records?')
                        ->modalDescription('Every selected row is deleted. There is no undo. This is audit logged.')
                        ->action(function ($records) use ($tableName) {
                            $n = 0;
                            foreach ($records as $record) {
                                $this->explorer()->delete($tableName, $record->getKey());
                                $n++;
                            }
                            $this->audit('RECORD_DELETED', $tableName, 'bulk:'.$n, ['rows' => $n]);
                            Notification::make()->title("Deleted {$n} records")->success()->send();
                        }),
                ]),
            ] : [])
            ->emptyStateHeading('No matching records')
            ->emptyStateDescription($canWrite
                ? 'Clear any search to see all records, or use New record or Import CSV to add data.'
                : 'Clear any search to see all records. This table is read-only for your current access.');

        if ($canWrite) {
            $table->recordAction('edit_record');
        }

        return $table;
    }

    /** Build a form schema from live column metadata (secret columns excluded). */
    protected function recordForm(array $columns, ?string $pk, bool $isCreate = false): array
    {
        $fields = [];
        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array(strtolower($name), ProtectedTables::SECRET_COLUMNS, true)) {
                continue;
            }
            if ($name === $pk && ! $isCreate) {
                $fields[] = TextInput::make($name)->disabled()->dehydrated(false);

                continue;
            }
            if ($name === $pk && $isCreate && str_contains((string) $col['default'], 'nextval')) {
                continue; // serial PK assigned by the database
            }
            if (preg_match('/bool/i', $col['type'])) {
                $fields[] = Toggle::make($name);
            } elseif (preg_match('/json/i', $col['type'])) {
                $fields[] = Textarea::make($name)->rows(3)->helperText('JSON');
            } elseif (preg_match('/int|numeric|decimal|float|double/i', $col['type'])) {
                $fields[] = TextInput::make($name)->numeric()->required(! $col['nullable']);
            } else {
                $fields[] = TextInput::make($name)->maxLength(500)->required(! $col['nullable'] && $col['default'] === null);
            }
        }

        return $fields;
    }

    protected function recordAttributes($record, array $columns, ?string $pk): array
    {
        $out = [];
        foreach ($columns as $col) {
            $name = $col['name'];
            if (in_array(strtolower($name), ProtectedTables::SECRET_COLUMNS, true)) {
                continue;
            }
            $value = $record->getAttribute($name);
            $out[$name] = is_array($value) ? json_encode($value) : $value;
        }

        return $out;
    }

    protected function formPayload(array $data, array $columns, ?string $pk, bool $isCreate): array
    {
        $byName = array_column($columns, null, 'name');
        $payload = [];
        foreach ($data as $key => $value) {
            if (! isset($byName[$key]) || in_array(strtolower($key), ProtectedTables::SECRET_COLUMNS, true)) {
                continue;
            }
            if ($key === $pk && ! $isCreate) {
                continue;
            }
            if (preg_match('/json/i', $byName[$key]['type']) && is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? json_encode($decoded) : $value;
            }
            if (preg_match('/timestamp|date/i', $byName[$key]['type']) && ($value === '' || $value === null)) {
                $value = $isCreate ? now() : $value;
            }
            $payload[$key] = $value === '' ? null : $value;
        }

        return $payload;
    }
}
