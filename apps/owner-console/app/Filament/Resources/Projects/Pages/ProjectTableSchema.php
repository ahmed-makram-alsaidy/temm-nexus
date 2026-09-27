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
                Section::make('Choose a table')->schema([
                    Html::make('<div class="cp-qa">'.($links ?: 'No tables in this database yet.').'</div>'),
                ]),
            ]);
        }

        $components = [];
        try {
            $explorer = $this->explorer();
            $table = $explorer->assertTable((string) $this->table);
            $components[] = Section::make("Columns · {$table}")
                ->schema([
                    RepeatableEntry::make('columns')->state($explorer->columns($table))->schema([
                        TextEntry::make('name')->badge(),
                        TextEntry::make('type'),
                        TextEntry::make('nullable')->formatStateUsing(fn ($s) => $s ? 'yes' : 'no'),
                        TextEntry::make('default')->placeholder('—'),
                        TextEntry::make('pk')->label('PK')->formatStateUsing(fn ($s) => $s ? '✓' : '—'),
                    ])->columns(5)->contained(false),
                ]);
            $fks = array_map(
                fn ($fk) => ['path' => "{$table}.{$fk['from']} → {$fk['to_table']}.{$fk['to_column']}"],
                $explorer->foreignKeys($table)
            );
            $fkSection = $fks === []
                ? Section::make('Relationships')->schema([TextEntry::make('none')->state('No foreign keys declared.')])
                : Section::make('Relationships')->schema([
                    RepeatableEntry::make('fks')->state($fks)
                        ->schema([TextEntry::make('path')])->contained(false),
                ]);
            $indexes = $explorer->indexes($table);
            $idxSection = Section::make('Indexes & size')->schema(array_filter([
                TextEntry::make('rows')->state(number_format($explorer->exactCount($table))),
                $indexes === []
                    ? TextEntry::make('idx_none')->state('No indexes.')
                    : RepeatableEntry::make('indexes')->state($indexes)->schema([
                        TextEntry::make('name')->badge(),
                        TextEntry::make('definition'),
                    ])->contained(false),
            ]));
            $components[] = Grid::make(2)->schema([$fkSection, $idxSection]);
            $components[] = Section::make('Actions')->schema([
                \Filament\Schemas\Components\Actions::make($this->schemaPageActions($table)),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Unknown table etc: plain notice, never the "unreachable" panel.
            $components[] = Section::make('Schema')->schema([
                TextEntry::make('notice')->state($e->getMessage() ?: 'Table not available.'),
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
            Action::make('browse_records')->label('Browse records')
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

        $actions[] = Action::make('add_column')->label('Add column')
            ->schema([
                TextInput::make('name')->required()->regex('/^[a-z_][a-z0-9_]{0,62}$/'),
                Select::make('type')->required()->options([
                    'text' => 'TEXT', 'varchar' => 'VARCHAR(255)', 'integer' => 'INTEGER',
                    'bigint' => 'BIGINT', 'boolean' => 'BOOLEAN', 'timestamptz' => 'TIMESTAMPTZ',
                    'date' => 'DATE', 'numeric' => 'NUMERIC', 'jsonb' => 'JSONB', 'uuid' => 'UUID',
                ]),
                Toggle::make('nullable')->label('Nullable')->default(true),
                TextInput::make('default')->label('Default')->placeholder('NULL / NOW() / …'),
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

        $actions[] = Action::make('add_fk')->label('Add relationship')
            ->schema([
                Select::make('column')->label('This table · column')->required()->options(array_combine($columns, $columns)),
                Select::make('to_table')->label('References table')->required()->options(array_combine($tables, $tables)),
                TextInput::make('to_column')->label('References column')->default('id')->required(),
            ])
            ->action(function (array $data) use ($table) {
                \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'database.write');
                \App\Services\ControlPlane\DdlService::addForeignKey(
                    $this->project(), $table, $data['column'], $data['to_table'], $data['to_column']
                );
                $this->audit('FK_CREATED', $table, null, ['column' => $data['column'], 'references' => $data['to_table'].'.'.$data['to_column']]);
                Notification::make()->title('Relationship created')->success()->send();
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
                    Notification::make()->title('Relationship removed')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project(), 'table' => $table]));
                });
        }

        return $actions;
    }
}
