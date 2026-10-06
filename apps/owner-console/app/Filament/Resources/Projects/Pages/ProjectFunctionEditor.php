<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\FunctionVersion;
use App\Models\ProjectFunction;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\FunctionRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Phase 20H function editor: settings, version history, deploy new version,
 * rollback, delete. Config is JSON edited with validation per executor type.
 */
class ProjectFunctionEditor extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public ?int $fnId = null;

    public function mount(int|string $record, int|string $fn): void
    {
        $this->record = $this->resolveRecord($record);
        $this->fnId = (int) $fn;
    }

    public function function(): ProjectFunction
    {
        return ProjectFunction::query()
            ->where('project_id', $this->project()->id)
            ->findOrFail($this->fnId ?? request()->route('fn'));
    }

    public function getTitle(): string|Htmlable
    {
        try {
            return 'Function · '.$this->function()->slug;
        } catch (\Throwable) {
            return __('labels.function');
        }
    }

    public function getBreadcrumbs(): array
    {
        try {
            return [static::projectUrl($this->project(), 'functions') => 'Functions', $this->function()->slug];
        } catch (\Throwable) {
            return ['Functions'];
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'functions.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('functions'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $fn = $this->function();
        $versions = $fn->versions()->orderByDesc('version')->get();
        $current = $fn->current_version_id;
        $rows = '';
        foreach ($versions as $v) {
            $isCurrent = $v->id === $current;
            $rollback = (! $isCurrent && CpAccess::allows(auth()->user(), 'functions.deploy'))
                ? ' <span style="font-size:.7rem">(rollback from header)</span>' : '';
            $rows .= '<tr><td>v'.$v->version.'</td>'
                .'<td>'.($isCurrent ? '<span class="cp-badge is-success">deployed</span>' : '<span class="cp-badge">archived</span>').$rollback.'</td>'
                .'<td><code>'.e(mb_substr(json_encode($v->config), 0, 160)).'</code></td>'
                .'<td>'.e($v->created_at?->format('M j, H:i') ?? '—').'</td></tr>';
        }
        $tester = ProjectFunctionTester::getUrl(['record' => $this->project(), 'fn' => $fn->id]);
        $editorUrl = static::getUrl(['record' => $this->project(), 'fn' => $fn->id]);
        $tabs = '<div class="cp-tabs" role="tablist" aria-label="Function views">'
            .'<button type="button" aria-selected="true" onclick="location.href=\''.e($editorUrl).'\'">Editor</button>'
            .'<button type="button" aria-selected="false" onclick="location.href=\''.e($tester).'\'">Test</button>'
            .'<button type="button" aria-selected="false" onclick="location.href=\''.e($tester).'#logs\'">Logs</button>'
            .'</div>';
        $invokePath = \App\Services\ControlPlane\TesterService::invokeUrl($this->project()->slug, $fn->slug);
        $grid = '<dl class="cp-kv">'
            .'<dt>Name</dt><dd>'.e($fn->name).'</dd>'
            .'<dt>Slug</dt><dd><code>'.e($fn->slug).'</code></dd>'
            .'<dt>Type</dt><dd>'.e($fn->type).'</dd>'
            .'<dt>Auth</dt><dd>'.e($fn->auth_mode).'</dd>'
            .'<dt>Methods</dt><dd>'.e(implode(', ', $fn->methods ?? [])).'</dd>'
            .'<dt>Timeout / rate</dt><dd>'.(int) $fn->timeout_s.'s · '.(int) $fn->rate_limit_per_min.'/min</dd>'
            .'<dt>Endpoint</dt><dd><code>'.e($invokePath).'</code> · <a href="'.e($tester).'">Open tester →</a></dd>'
            .'</dl>';
        $history = '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>'.e(__('labels.version')).'</th><th>'.e(__('labels.state')).'</th><th>'.e(__('labels.th_config_preview')).'</th><th>'.e(__('labels.th_deployed')).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';

        return $schema->components([
            Html::make($tabs),
            Section::make(__('labels.fn_function'))->schema([Html::make($grid)])->compact(),
            Section::make(__('labels.fn_versions'))->schema([Html::make($history)])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        try {
            $fn = $this->function();
        } catch (\Throwable) {
            return [];
        }
        if (! CpAccess::allows(auth()->user(), 'functions.deploy')) {
            return [];
        }
        $versions = $fn->versions()->orderByDesc('version')->pluck('version', 'version')->all();

        $actions[] = Action::make('deploy_version')->label(__('labels.deploy_new_version'))
            ->schema([
                Textarea::make('config_json')->label(__('labels.version_config_json'))
                    ->required()->rows(12)
                    ->default(json_encode($fn->versions()->where('id', $fn->current_version_id)->value('config') ?? [], JSON_PRETTY_PRINT))
                    ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false'])
                    ->helperText(__('labels.validated_per_executor_type_before_deplo')),
            ])
            ->action(function (array $data) use ($fn) {
                CpAccess::require(auth()->user(), 'functions.deploy');
                $config = json_decode((string) $data['config_json'], true);
                abort_unless(is_array($config), 422, 'Invalid JSON.');
                self::validateConfig($fn->type, $config);
                FunctionRunner::deploy($fn, $config, $fn->type);
                Notification::make()->title(__('labels.new_version_deployed'))->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'fn' => $fn->id]));
            });

        if (count($versions) > 1) {
            $actions[] = Action::make('rollback')->label(__('labels.rollback'))
                ->color('warning')->requiresConfirmation()
                ->modalDescription(__('labels.points_the_function_at_a_previous_versio'))
                ->schema([Select::make('version')->label(__('labels.version'))->required()->options($versions)])
                ->action(function (array $data) use ($fn) {
                    CpAccess::require(auth()->user(), 'functions.deploy');
                    FunctionRunner::rollback($fn, (int) $data['version']);
                    Notification::make()->title("Rolled back to v{$data['version']}")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project(), 'fn' => $fn->id]));
                });
        }

        $actions[] = Action::make('toggle')->label($fn->enabled ? 'Disable' : 'Enable')
            ->color($fn->enabled ? 'warning' : 'success')->requiresConfirmation()
            ->action(function () use ($fn) {
                CpAccess::require(auth()->user(), 'functions.deploy');
                $fn->forceFill(['enabled' => ! $fn->enabled])->save();
                Notification::make()->title($fn->enabled ? 'Enabled' : 'Disabled')->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'fn' => $fn->id]));
            });

        $actions[] = Action::make('settings')->label(__('labels.settings'))
            ->schema([
                TextInput::make('name')->default($fn->name)->required()->maxLength(120),
                Select::make('auth_mode')->options([
                    'key' => 'Project API key', 'public' => 'Public',
                    'user' => 'Authenticated user', 'internal' => 'Internal (owner only)',
                ])->default($fn->auth_mode)->required(),
                Select::make('methods')->multiple()->options([
                    'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE',
                ])->default($fn->methods ?? ['GET']),
                TextInput::make('timeout_s')->numeric()->default($fn->timeout_s),
                TextInput::make('rate_limit_per_min')->numeric()->default($fn->rate_limit_per_min),
            ])
            ->action(function (array $data) use ($fn) {
                CpAccess::require(auth()->user(), 'functions.deploy');
                $fn->forceFill([
                    'name' => $data['name'], 'auth_mode' => $data['auth_mode'],
                    'methods' => array_values($data['methods'] ?? ['GET']),
                    'timeout_s' => max(1, (int) ($data['timeout_s'] ?? 10)),
                    'rate_limit_per_min' => max(1, (int) ($data['rate_limit_per_min'] ?? 60)),
                ])->save();
                Notification::make()->title(__('labels.settings_saved'))->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'fn' => $fn->id]));
            });

        $actions[] = Action::make('delete')->label(__('labels.delete'))->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('labels.deletes_the_function_all_versions_and_in'))
            ->schema([TextInput::make('confirm')->label(__('labels.type_the_function_slug'))->required()])
            ->action(function (array $data) use ($fn) {
                CpAccess::require(auth()->user(), 'functions.deploy');
                abort_unless($data['confirm'] === $fn->slug, 422, 'Slug does not match.');
                $slug = $fn->slug;
                $fn->invocations()->delete();
                $fn->versions()->delete();
                $fn->delete();
                $this->audit('FUNCTION_DELETED', 'function', null, ['slug' => $slug]);
                Notification::make()->title("Function {$slug} deleted")->success()->send();
                $this->redirect(ProjectFunctions::getUrl(['record' => $this->project()]));
            });

        return $actions;
    }

    public static function validateConfig(string $type, array $config): void
    {
        match ($type) {
            'static' => (function () use ($config) {
                abort_unless(isset($config['body']), 422, 'static needs "body".');
                $status = (int) ($config['status'] ?? 200);
                abort_unless($status >= 200 && $status <= 599, 422, 'Invalid status.');
            })(),
            'db_lookup' => (function () use ($config) {
                foreach (['table', 'columns', 'key_column'] as $k) {
                    abort_unless(! empty($config[$k]), 422, "db_lookup needs \"{$k}\".");
                }
                abort_unless(is_array($config['columns']), 422, '"columns" must be an array.');
            })(),
            'transform' => (function () use ($config) {
                abort_unless(
                    isset($config['pick']) || isset($config['rename']) || ! empty($config['passthrough']),
                    422, 'transform needs "pick", "rename" or passthrough.'
                );
            })(),
            default => abort(422, 'Unknown executor type.'),
        };
    }
}
