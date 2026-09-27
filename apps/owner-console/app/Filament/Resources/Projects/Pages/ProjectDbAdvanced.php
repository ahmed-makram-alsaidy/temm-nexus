<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\DbAdvancedService;
use App\Services\ControlPlane\DbFunctionService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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

/**
 * Phase 20R database inspector (indexes, triggers, extensions, roles/RLS,
 * read-only ERD): read-only inventory plus guided, allowlisted changes.
 * Labeled "Inspector" in navigation — "Advanced" said nothing about content.
 */
class ProjectDbAdvanced extends Page
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
        return 'Inspector';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'Inspector'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('db-advanced'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        try {
            $p = $this->project();
            $erd = DbAdvancedService::erd($p);
            $indexes = DbAdvancedService::indexes($p);
            $triggers = DbAdvancedService::triggers($p);
            $extensions = DbAdvancedService::extensions($p);
            $authz = DbAdvancedService::authorization($p);
        } catch (\Throwable $e) {
            return $schema->components([static::connectionError($e)]);
        }

        $idxRows = '';
        foreach ($indexes as $i) {
            $idxRows .= '<tr><td><code>'.e($i['table']).'</code></td><td><code>'.e($i['name']).'</code></td>'
                .'<td class="cp-num">'.e($i['size']).'</td><td class="cp-num">'.number_format($i['scans']).'</td></tr>';
        }
        $trgRows = '';
        foreach ($triggers as $t) {
            $trgRows .= '<tr><td><code>'.e($t['name']).'</code></td><td><code>'.e($t['table']).'</code></td>'
                .'<td>'.e($t['event']).'</td><td><code>'.e($t['function']).'()</code></td>'
                .'<td>'.($t['enabled'] ? '<span class="cp-badge is-success">on</span>' : '<span class="cp-badge">off</span>').'</td></tr>';
        }
        $extRows = '';
        foreach ($extensions['allowlist'] as $name => $info) {
            $extRows .= '<tr><td><code>'.e($name).'</code></td><td>'.e($info['description']).'</td>'
                .'<td>'.($info['installed'] ? '<span class="cp-badge is-success">installed</span>' : '<span class="cp-badge">absent</span>').'</td></tr>';
        }
        $roleRows = '';
        foreach ($authz['roles'] as $r) {
            $roleRows .= '<tr><td><code>'.e($r['name']).'</code></td><td>'.($r['login'] ? 'login' : 'no login').'</td></tr>';
        }
        $polRows = '';
        foreach ($authz['policies'] as $pol) {
            $polRows .= '<tr><td><code>'.e($pol['table']).'</code></td><td><code>'.e($pol['name']).'</code></td><td>'.e($pol['command']).'</td></tr>';
        }

        return $schema->components([
            Section::make('Entity relationships (read-only)')->schema([
                Html::make(DbAdvancedService::erdSvg($erd)
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.count($erd['tables']).' tables · '
                    .count($erd['edges']).' foreign keys. <a href="'.e(static::projectUrl($p, 'erd')).'">Open interactive ERD →</a> '
                    .'Create/remove relationships from the Schema page.</p>'),
            ]),
            Section::make('Indexes ('.count($indexes).')')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Table</th><th>Index</th>'
                    .'<th class="cp-num">Size</th><th class="cp-num">Scans</th></tr></thead><tbody>'
                    .($idxRows ?: '<tr><td colspan="4">No indexes.</td></tr>').'</tbody></table></div>'),
            ])->compact(),
            Section::make('Triggers ('.count($triggers).')')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Trigger</th><th>Table</th>'
                    .'<th>Event</th><th>Function</th><th>State</th></tr></thead><tbody>'
                    .($trgRows ?: '<tr><td colspan="5">No user triggers.</td></tr>').'</tbody></table></div>'),
            ])->compact(),
            Section::make('Extensions')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Extension</th><th>Purpose</th>'
                    .'<th>Status</th></tr></thead><tbody>'.$extRows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Installs are allowlisted; the database may still refuse '
                    .'without superuser/DBA rights — the outcome is reported, never hidden.</p>'),
            ])->compact(),
            Section::make('Authorization')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Role</th><th>Can log in</th></tr></thead><tbody>'
                    .($roleRows ?: '<tr><td colspan="2">—</td></tr>').'</tbody></table></div>'
                    .'<div class="cp-tablewrap" style="margin-top:.5rem"><table class="cp-grid"><thead><tr><th>Table</th><th>RLS policy</th><th>Command</th></tr></thead><tbody>'
                    .($polRows ?: '<tr><td colspan="3">No RLS policies.</td></tr>').'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e($authz['note']).'</p>'),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'database.write')) {
            return [];
        }
        try {
            $explorer = $this->explorer();
            $tables = array_map(fn ($t) => $t['name'], array_filter(
                $explorer->tables(), fn ($t) => $t['type'] === 'table'
            ));
            $functions = array_column(DbFunctionService::list($this->project()), 'name');
            $triggers = DbAdvancedService::triggers($this->project());
        } catch (\Throwable) {
            return [];
        }
        $trgOptions = [];
        foreach ($triggers as $t) {
            $trgOptions[$t['table'].'.'.$t['name']] = $t['table'].'.'.$t['name'];
        }

        return array_filter([
            Action::make('create_index')->label('New index')
                ->schema([
                    Select::make('table')->required()->options(array_combine($tables, $tables)),
                    CheckboxList::make('columns')->label('Columns (1–5)')->required()->columns(2)
                        ->options(fn ($get) => $get('table')
                            ? array_combine(
                                array_column($explorer->columns($get('table')), 'name'),
                                array_column($explorer->columns($get('table')), 'name')
                            ) : []),
                    Toggle::make('unique')->label('Unique'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    $name = DbAdvancedService::createIndex($this->project(), $data['table'], array_values($data['columns'] ?? []), (bool) ($data['unique'] ?? false));
                    $this->audit('TABLE_ALTERED', $data['table'], $name, ['index' => $name]);
                    Notification::make()->title("Index {$name} created")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $functions === [] ? null : Action::make('create_trigger')->label('New trigger')
                ->schema([
                    TextInput::make('name')->required()->regex('/^[a-z_][a-z0-9_]{0,62}$/'),
                    Select::make('table')->required()->options(array_combine($tables, $tables)),
                    Select::make('timing')->required()->options(['BEFORE' => 'BEFORE', 'AFTER' => 'AFTER']),
                    Select::make('event')->required()->options(['INSERT' => 'INSERT', 'UPDATE' => 'UPDATE', 'DELETE' => 'DELETE']),
                    Select::make('function')->label('Trigger function (must return trigger)')->required()
                        ->options(array_combine($functions, $functions)),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    DbAdvancedService::createTrigger(
                        $this->project(), $data['name'], $data['table'],
                        $data['timing'], $data['event'], $data['function']
                    );
                    $this->audit('TABLE_ALTERED', $data['table'], $data['name'], ['trigger' => $data['name']]);
                    Notification::make()->title("Trigger {$data['name']} created")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $trgOptions === [] ? null : Action::make('drop_trigger')->label('Drop trigger')
                ->color('danger')->requiresConfirmation()
                ->schema([Select::make('ref')->label('Trigger')->required()->options($trgOptions)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    [$table, $name] = explode('.', $data['ref'], 2);
                    DbAdvancedService::dropTrigger($this->project(), $table, $name);
                    $this->audit('TABLE_ALTERED', $table, $name, ['trigger_dropped' => $name]);
                    Notification::make()->title("Trigger {$name} dropped")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('install_extension')->label('Install extension')
                ->schema([
                    Select::make('name')->label('Allowlisted extension')->required()->options(
                        array_combine(array_keys(DbAdvancedService::EXTENSION_ALLOWLIST), array_keys(DbAdvancedService::EXTENSION_ALLOWLIST))
                    ),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    $result = DbAdvancedService::installExtension($this->project(), $data['name']);
                    $note = Notification::make()->title($result['message']);
                    $result['ok'] ? $note->success()->send() : $note->warning()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }
}
