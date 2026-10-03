<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\DbFunctionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 20G PostgreSQL functions: list, create/edit (SQL + PL/pgSQL),
 * test-invoke with controlled inputs, delete with confirmation.
 * New functions default to SECURITY INVOKER; DEFINER shows a warning and is
 * explained in the UI — never a silent permission workaround.
 */
class ProjectDbFunctions extends Page
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
        return __('labels.database_functions');
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Database', 'Functions'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--db-functions'])
            ->components([$this->subnavSection('functions'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        try {
            $functions = DbFunctionService::list($this->project());
            $canWrite = CpAccess::allows(auth()->user(), 'database.write');
            $body = $this->catalogHtml($functions, $canWrite);
        } catch (\Throwable $e) {
            $body = '<div class="cp-error"><strong>Function catalog unavailable.</strong> '
                .'The project database could not be read. Check project connectivity and try again.</div>';
        }

        return $schema->components([Html::make($body)]);
    }

    /** @param list<array{name:string,args:string,returns:string,language:string,security:string,owner:string}> $functions */
    protected function catalogHtml(array $functions, bool $canWrite): string
    {
        $langs = array_values(array_unique(array_map(fn ($f) => (string) ($f['language'] ?? ''), $functions)));
        sort($langs);
        $langOpts = '<option value="">All languages</option>';
        foreach ($langs as $l) {
            $langOpts .= '<option value="'.e($l).'">'.e($l).'</option>';
        }

        $h = '<p class="cp-fn__lede">Reusable PostgreSQL logic.</p>';
        // Alpine.js scope must wrap BOTH toolbar and table so x-show on rows works.
        $h .= '<div x-data="{ q: \'\', lang: \'\', sec: \'\' }">';
        $h .= '<div class="cp-fn__toolbar">'
            .'<input class="cp-toolbar__search" type="search" placeholder="Search functions..." aria-label="Search functions" x-model="q">'
            .'<select class="cp-fn__select" aria-label="Filter by language" x-model="lang">'.$langOpts.'</select>'
            .'<select class="cp-fn__select" aria-label="Filter by security" x-model="sec">'
            .'<option value="">All security</option><option value="INVOKER">INVOKER</option><option value="DEFINER">DEFINER</option></select>'
            .'<span class="cp-toolbar__count">'.count($functions).' functions</span>'
            .'<span class="cp-badge '.($canWrite ? 'is-warning' : 'is-info').'">'.($canWrite ? 'Write access' : 'Read-only access').'</span>'
            .'</div>';

        if ($functions === []) {
            $h .= '<div class="cp-empty cp-fn__empty">'
                .'<div class="cp-empty__icon">✕</div>'
                .'<h3 class="cp-empty__title">No database functions yet</h3>'
                .'<p class="cp-empty__hint">Create reusable logic directly in PostgreSQL.</p>'
                .($canWrite ? '<p class="cp-empty__hint">Start with SECURITY INVOKER.</p>' : '<p class="cp-empty__hint">Creating functions requires database write permission.</p>')
                .'</div></div>';

            return $h;
        }

        $hasDefiner = false;
        $rows = '';
        foreach ($functions as $f) {
            $isDefiner = ($f['security'] ?? '') === 'DEFINER';
            $hasDefiner = $hasDefiner || $isDefiner;
            $sec = $isDefiner
                ? '<span class="cp-badge is-danger">DEFINER · privileged</span>'
                : '<span class="cp-badge is-success">INVOKER</span>';
            $rows .= '<tr data-fn x-show="(q === \'\' || \''.e(strtolower((string) ($f['name'] ?? ''))).'\'.includes(q.toLowerCase()))'
                .' && (lang === \'\' || \''.e((string) ($f['language'] ?? '')).'\' === lang)'
                .' && (sec === \'\' || \''.e((string) ($f['security'] ?? '')).'\' === sec)">'
                .'<td><code>'.e($f['name']).'</code></td>'
                .'<td><code>'.e($f['args'] ?: '—').'</code></td>'
                .'<td><code>'.e($f['returns']).'</code></td>'
                .'<td>'.e($f['language']).'</td><td>'.$sec.'</td><td>'.e($f['owner']).'</td></tr>';
        }

        if ($hasDefiner) {
            $h .= '<p class="cp-fn__warn">⚠ This schema contains SECURITY DEFINER function(s) — they run with the owner\'s privileges. Review bodies before invoking.</p>';
        }

        $h .= '<div class="cp-tablewrap" tabindex="0" role="region" aria-label="Database function catalog">'
            .'<table class="cp-grid"><thead><tr>'
            .'<th scope="col">Name</th><th scope="col">Arguments</th><th scope="col">Returns</th>'
            .'<th scope="col">Language</th><th scope="col">Security</th><th scope="col">Owner</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<details class="cp-fn__about"><summary>About INVOKER vs DEFINER</summary>'
            .'<p>INVOKER (default) runs with the caller\'s privileges. DEFINER runs with the owner\'s privileges — never use it to bypass access controls. '
            .'Test / invoke executes the function and may change data; deleting a function can break dependent triggers and views.</p></details>'
            .'</div>';

        return $h;
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'database.write')) {
            return [];
        }
        $project = $this->project();
        try {
            $functions = DbFunctionService::list($project);
        } catch (\Throwable) {
            $functions = [];
        }
        $options = [];
        foreach ($functions as $f) {
            $options[(string) $f['oid']] = $f['name'].'('.$f['args'].')';
        }

        return array_filter([
            Action::make('new_function')->label(__('labels.new_function'))->icon('heroicon-o-plus')
                ->schema($this->functionForm())
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    DbFunctionService::save(
                        $this->project(), $data['name'], $data['args'] ?? '',
                        $data['returns'] ?? 'void', $data['language'], $data['security'], $data['body']
                    );
                    $this->audit('DB_FUNCTION_CREATED', 'function', $data['name'], ['security' => $data['security']]);
                    Notification::make()->title("Function {$data['name']} saved")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $options === [] ? null : Action::make('test_function')->label(__('labels.test_invoke'))
                ->schema([
                    Select::make('oid')->label(__('labels.function'))->required()->options($options),
                    Textarea::make('args_json')->label(__('labels.arguments_json_array_max_10'))
                        ->default('[]')->rows(2)
                        ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false']),
                ])
                ->action(function (array $data) {
                    $args = json_decode((string) ($data['args_json'] ?? '[]'), true);
                    abort_unless(is_array($args), 422, 'Arguments must be a JSON array.');
                    $result = DbFunctionService::invoke($this->project(), (int) $data['oid'], array_values($args));
                    $this->audit('DB_FUNCTION_TESTED', 'function', $data['oid'], ['ok' => $result['ok']]);
                    if (! $result['ok']) {
                        Notification::make()->title(__('labels.invocation_failed'))->body(mb_substr((string) $result['error'], 0, 300))->danger()->send();

                        return;
                    }
                    Notification::make()->title("OK in {$result['duration_ms']} ms · ".count($result['rows']).' row(s)')
                        ->body(mb_substr(json_encode($result['rows']), 0, 500))->success()->send();
                }),
            $options === [] ? null : Action::make('edit_function')->label(__('labels.edit'))
                ->schema(array_merge(
                    [Select::make('oid')->label(__('labels.function'))->required()->options($options)->live()],
                    $this->functionForm(true)
                ))
                ->fillForm(fn () => [])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    DbFunctionService::save(
                        $this->project(), $data['name'], $data['args'] ?? '',
                        $data['returns'] ?? 'void', $data['language'], $data['security'], $data['body']
                    );
                    $this->audit('DB_FUNCTION_CREATED', 'function', $data['name'], ['edited' => true]);
                    Notification::make()->title("Function {$data['name']} updated")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $options === [] ? null : Action::make('delete_function')->label(__('labels.delete'))
                ->color('danger')->requiresConfirmation()
                ->modalDescription(__('labels.drops_the_function_dependent_triggers_vi'))
                ->schema([Select::make('oid')->label(__('labels.function'))->required()->options($options)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'database.write');
                    $name = DbFunctionService::drop($this->project(), (int) $data['oid']);
                    $this->audit('DB_FUNCTION_DELETED', 'function', $name);
                    Notification::make()->title("Function {$name} deleted")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }

    /** @return list<object> */
    protected function functionForm(bool $withName = true): array
    {
        $fields = [];
        if ($withName) {
            // Edit flow keeps the catalog name (renames via DROP+CREATE, explicit).
            $fields[] = TextInput::make('name')->label(__('labels.function_name'))->required()
                ->regex('/^[a-z_][a-z0-9_]{0,62}$/')
                ->helperText(__('labels.lowercase_identifier_must_match_the_sele'));
        } else {
            $fields[] = TextInput::make('name')->label(__('labels.function_name'))->required()
                ->regex('/^[a-z_][a-z0-9_]{0,62}$/');
        }

        return array_merge($fields, [
            TextInput::make('args')->label(__('labels.arguments'))->placeholder('user_id integer, tag text')
                ->helperText(__('labels.comma_separated_name_type_pairs_empty_no')),
            TextInput::make('returns')->label(__('labels.return_type'))->default('void')->placeholder('void / integer / text / …'),
            Select::make('language')->required()->options(['sql' => 'SQL', 'plpgsql' => 'PL/pgSQL'])->default('sql'),
            Select::make('security')->required()->options(['INVOKER' => 'INVOKER (safe default)', 'DEFINER' => 'DEFINER (privileged — read the warning)'])
                ->default('INVOKER'),
            Textarea::make('body')->label(__('labels.body'))->required()->rows(10)
                ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false'])
                ->helperText(__('labels.sql_or_pl_pgsql_body_no_cp_delimiter_ins')),
        ]);
    }
}
