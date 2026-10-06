<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

class ProjectTableSchema extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'table')]
    public ?string $table = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->table ??= request()->query('table');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->table ? "Schema · {$this->table}" : 'Schema';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'Schema'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('database'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        // No table chosen yet: picker, not an error (20E fix — the old code
        // reported "database unreachable" for this perfectly healthy state).
        if ($this->table === null || $this->table === '') {
            try {
                $tables = array_map(fn ($t) => $t['name'], $this->explorer()->tables());
            } catch (\Throwable $e) {
                return $schema->components([static::connectionError($e)]);
            }
            $links = '';
            foreach ($tables as $name) {
                $url = static::projectUrl($this->project(), 'table-schema', ['table' => $name]);
                $links .= '<a class="cp-qa__btn" href="'.e($url).'">'.e($name).'</a>';
            }

            return $schema->components([
                Section::make(__('labels.ts_choose_table'))->schema([
                    Html::make('<div class="cp-qa">'.($links ?: e(__('labels.ts_no_tables'))).'</div>'),
                ]),
            ]);
        }

        $components = [];
        try {
            $explorer = $this->explorer();
            $table = $explorer->assertTable((string) $this->table);
            $components[] = Section::make(__('labels.ts_columns_table', ['table' => $table]))
                ->schema([
                    RepeatableEntry::make('columns')->state($explorer->columns($table))->schema([
                        TextEntry::make('name')->badge(),
                        TextEntry::make('type')->label(__('labels.ts_type')),
                        TextEntry::make('nullable')->label(__('labels.ts_nullable'))->formatStateUsing(fn ($s) => $s ? __('labels.inf_yes') : __('labels.inf_no')),
                        TextEntry::make('default')->label(__('labels.ts_default'))->placeholder('—'),
                        TextEntry::make('pk')->label(__('labels.pk'))->formatStateUsing(fn ($s) => $s ? '✓' : '—'),
                    ])->columns(5)->contained(false),
                ]);
            $fks = array_map(
                fn ($fk) => ['path' => "{$table}.{$fk['from']} → {$fk['to_table']}.{$fk['to_column']}"],
                $explorer->foreignKeys($table)
            );
            $fkSection = $fks === []
                ? Section::make(__('labels.ts_relationships'))->schema([TextEntry::make('none')->label(__('labels.ts_fk'))->state(__('labels.ts_no_fks'))])
                : Section::make(__('labels.ts_relationships'))->schema([
                    RepeatableEntry::make('fks')->state($fks)
                        ->schema([TextEntry::make('path')->label(__('labels.ts_fk'))])->contained(false),
                ]);
            $indexes = $explorer->indexes($table);
            $idxSection = Section::make(__('labels.ts_indexes_size'))->schema(array_filter([
                TextEntry::make('rows')->label(__('labels.ts_rows'))->state(number_format($explorer->exactCount($table))),
                $indexes === []
                    ? TextEntry::make('idx_none')->label(__('labels.ts_indexes'))->state(__('labels.ts_no_indexes'))
                    : RepeatableEntry::make('indexes')->state($indexes)->schema([
                        TextEntry::make('name')->badge(),
                        TextEntry::make('definition')->label(__('labels.ts_definition')),
                    ])->contained(false),
            ]));
            $components[] = Grid::make(2)->schema([$fkSection, $idxSection]);
            $components[] = Section::make(__('labels.ts_actions'))->schema([
                \Filament\Schemas\Components\Actions::make($this->schemaPageActions($table)),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Unknown table etc: plain notice, never the "unreachable" panel.
            // The raw reason stays in the log (H3); the face is product copy.
            report($e);
            $components[] = Section::make(__('labels.ts_schema'))->schema([
                TextEntry::make('notice')->label(__('labels.ts_notice'))->state(__('labels.ts_table_unavailable')),
            ]);
        } catch (\Throwable $e) {
            $components[] = static::connectionError($e);
        }

        return $schema->components($components);
    }

    /** @return list<\Filament\Actions\Action> */
    protected function schemaPageActions(string $table): array
    {
        $actions = [
            Action::make('browse_records')->label(__('labels.browse_records'))
                ->url(ProjectTableRecords::getUrl(['record' => $this->project(), 'table' => $table])),
        ];
        if (! \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'database.write')) {
            return $actions;
        }
        $explorer = $this->explorer();
        $columns = array_column($explorer->columns($table), 'name');
        $tables = array_map(fn ($t) => $t['name'], array_filter(
            $explorer->tables(),
            fn ($t) => $t['type'] === 'table'
        ));

        $actions[] = Action::make('add_column')->label(__('labels.add_column'))
            ->schema([
                TextInput::make('name')->required()->regex('/^[a-z_][a-z0-9_]{0,62}$/'),
                Select::make('type')->required()->options([
                    'text' => 'TEXT', 'varchar' => 'VARCHAR(255)', 'integer' => 'INTEGER',
                    'bigint' => 'BIGINT', 'boolean' => 'BOOLEAN', 'timestamptz' => 'TIMESTAMPTZ',
                    'date' => 'DATE', 'numeric' => 'NUMERIC', 'jsonb' => 'JSONB', 'uuid' => 'UUID',
                ]),
                Toggle::make('nullable')->label(__('labels.nullable'))->default(true),
                TextInput::make('default')->label(__('labels.default'))->placeholder(__('labels.null_now')),
            ])
            ->action(function (array $data) use ($table) {
                \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'database.write');
                \App\Services\ControlPlane\DdlService::addColumn($this->project(), $table, [
                    'name' => $data['name'], 'type' => $data['type'],
                    'nullable' => (bool) ($data['nullable'] ?? true),
                    'default' => $data['default'] ?? null, 'pk' => false, 'unique' => false,
                ]);
                $this->audit('TABLE_ALTERED', $table, null, ['added_column' => $data['name']]);
                Notification::make()->title("Column {$data['name']} added")->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'table' => $table]));
            });

        $actions[] = Action::make('add_fk')->label(__('labels.add_relationship'))
            ->schema([
                Select::make('column')->label(__('labels.this_table_column'))->required()->options(array_combine($columns, $columns)),
                Select::make('to_table')->label(__('labels.references_table'))->required()->options(array_combine($tables, $tables)),
                TextInput::make('to_column')->label(__('labels.references_column'))->default('id')->required(),
            ])
            ->action(function (array $data) use ($table) {
                \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'database.write');
                \App\Services\ControlPlane\DdlService::addForeignKey(
                    $this->project(), $table, $data['column'], $data['to_table'], $data['to_column']
                );
                $this->audit('FK_CREATED', $table, null, ['column' => $data['column'], 'references' => $data['to_table'].'.'.$data['to_column']]);
                Notification::make()->title(__('labels.relationship_created'))->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'table' => $table]));
            });

        foreach ($explorer->foreignKeys($table) as $fk) {
            $constraint = "fk_{$table}_{$fk['from']}";
            $actions[] = Action::make('drop_fk_'.$constraint)->label("Remove FK {$fk['from']}")
                ->color('danger')->requiresConfirmation()
                ->modalDescription("Drops {$constraint}. Rows are kept. Audit logged.")
                ->action(function () use ($table, $constraint) {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'database.write');
                    \App\Services\ControlPlane\DdlService::dropForeignKey($this->project(), $table, $constraint);
                    $this->audit('FK_DROPPED', $table, $constraint);
                    Notification::make()->title(__('labels.relationship_removed'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project(), 'table' => $table]));
                });
        }

        return $actions;
    }
}
