<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\DdlService;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Phase 20E Database Studio home: real table grid (exact counts, search),
 * visual table creation, typed-confirm drops. Structural work stays
 * project-scoped; protected tables are untouchable here.
 */
class ProjectDatabase extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'q')]
    public ?string $q = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Tables';
    }

    public function getBreadcrumbs(): array
    {
        return ['Tables'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('database'), EmbeddedSchema::make('infolist')]);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    public function infolist(Schema $schema): Schema
    {
        try {
            $explorer = $this->explorer();
            $tables = $explorer->tables();
            $q = trim((string) $this->q);
            if ($q !== '') {
                $tables = array_values(array_filter(
                    $tables,
                    fn ($t) => stripos($t['name'], $q) !== false
                ));
            }
            // reltuples is -1 before ANALYZE: fall back to exact counts.
            foreach ($tables as &$t) {
                if (($t['rows'] ?? null) === null || $t['rows'] < 0) {
                    try {
                        $t['rows'] = $t['type'] === 'table' ? $explorer->exactCount($t['name']) : null;
                    } catch (\Throwable) {
                        $t['rows'] = null;
                    }
                }
            }
            unset($t);

            $rows = '';
            foreach ($tables as $t) {
                $browse = ProjectTableRecords::getUrl(['record' => $this->project(), 'table' => $t['name']]);
                $schemaUrl = ProjectTableSchema::getUrl(['record' => $this->project(), 'table' => $t['name']]);
                $rows .= '<tr><td><code>'.e($t['name']).'</code></td>'
                    .'<td>'.e($t['type']).'</td>'
                    .'<td class="cp-num">'.($t['rows'] === null ? '—' : number_format($t['rows'])).'</td>'
                    .'<td><a href="'.e($browse).'">Browse →</a></td>'
                    .'<td><a href="'.e($schemaUrl).'">Schema →</a></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="5">No tables match.</td></tr>';
            }
            $grid = '<form method="GET" class="cp-toolbar">'
                .'<input type="search" name="q" class="cp-toolbar__search" placeholder="Filter tables…" value="'.e($q).'">'
                .'<button class="cp-btn" type="submit">Filter</button>'
                .'<span class="cp-toolbar__count">'.count($tables).' tables &amp; views</span></form>'
                .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                .'<th>Name</th><th>Type</th><th class="cp-num">Rows</th><th>Records</th><th>Schema</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';

            $components = [
                Section::make('Tables & views')->schema([Html::make($grid)])->compact(),
            ];
        } catch (\Throwable $e) {
            $components = [static::connectionError($e)];
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            CpAccess::allows(auth()->user(), 'database.write') ? Action::make('create_table')
                ->label('New table')
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')->label('Table name')->required()
                        ->regex('/^[a-z_][a-z0-9_]{0,62}$/')
                        ->helperText('Lowercase letters, digits, underscores.'),
                    Repeater::make('columns')->label('Columns')->minItems(1)->defaultItems(1)
                        ->schema([
                            TextInput::make('name')->required()->regex('/^[a-z_][a-z0-9_]{0,62}$/'),
                            Select::make('type')->required()->options([
                                'text' => 'TEXT', 'varchar' => 'VARCHAR(255)',
                                'integer' => 'INTEGER', 'bigint' => 'BIGINT',
                                'boolean' => 'BOOLEAN', 'timestamptz' => 'TIMESTAMPTZ',
                                'date' => 'DATE', 'numeric' => 'NUMERIC',
                                'jsonb' => 'JSONB', 'uuid' => 'UUID',
                                'serial' => 'SERIAL',
                            ]),
                            Toggle::make('nullable')->label('Nullable')->default(true),
                            TextInput::make('default')->label('Default')->placeholder('NULL / NOW() / …'),
                            Toggle::make('pk')->label('PK'),
                            Toggle::make('unique')->label('Unique'),
                        ])->columns(3),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    DdlService::createTable($this->project(), $data['name'], array_values($data['columns'] ?? []));
                    $this->audit('TABLE_CREATED', 'table', $data['name'], ['columns' => count($data['columns'] ?? [])]);
                    Notification::make()->title("Table {$data['name']} created")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }) : null,
            CpAccess::allows(auth()->user(), 'database.write') ? Action::make('drop_table')
                ->label('Drop table')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Drop table permanently?')
                ->modalDescription('All rows are destroyed. There is no undo. Type the exact table name to confirm.')
                ->schema([
                    TextInput::make('table')->label('Table name')->required(),
                    TextInput::make('confirm')->label('Type the table name again')->required(),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    abort_unless($data['table'] === $data['confirm'], 422, 'Names do not match.');
                    $explorer = $this->explorer();
                    $explorer->assertTable($data['table']);
                    DdlService::dropTable($this->project(), $data['table']);
                    $this->audit('TABLE_DROPPED', 'table', $data['table']);
                    Notification::make()->title("Table {$data['table']} dropped")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }) : null,
        ]);
    }
}
