<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
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
 * Phase 20H Server Functions list + creation. Versioned modules executed by
 * audited bounded executors (static / db_lookup / transform) — documented
 * honestly as such; no arbitrary code execution anywhere in this flow.
 */
class ProjectFunctions extends Page
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
        return 'Functions';
    }

    public function getBreadcrumbs(): array
    {
        return ['Functions'];
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
        $functions = ProjectFunction::query()->where('project_id', $this->project()->id)->orderBy('slug')->get();
        $rows = '';
        foreach ($functions as $f) {
            $editor = ProjectFunctionEditor::getUrl(['record' => $this->project(), 'fn' => $f->id]);
            $tester = ProjectFunctionTester::getUrl(['record' => $this->project(), 'fn' => $f->id]);
            $state = $f->enabled ? '<span class="cp-badge is-success">enabled</span>' : '<span class="cp-badge">disabled</span>';
            $rows .= '<tr><td><a href="'.e($editor).'"><strong>'.e($f->name).'</strong></a><br><code style="font-size:.7rem">'.e($f->slug).'</code></td>'
                .'<td><span class="cp-badge">'.e($f->auth_mode).'</span></td>'
                .'<td>'.e(implode(', ', $f->methods ?? [])).'</td>'
                .'<td class="cp-num">v'.(int) ($f->versions()->max('version') ?? 0).'</td>'
                .'<td>'.$state.'</td>'
                .'<td style="white-space:nowrap">'.e($f->updated_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td style="white-space:nowrap"><a href="'.e($editor).'">Editor</a> · <a href="'.e($tester).'">Test</a> · <a href="'.e($tester).'#logs">Logs</a></td></tr>';
        }
        $grid = '<div class="cp-toolbar"><span class="cp-toolbar__count">'.$functions->count().' functions</span></div>';
        if ($rows === '') {
            $grid .= '<div class="cp-empty"><div class="cp-empty__icon">λ</div>'
                .'<div class="cp-empty__title">No functions yet</div>'
                .'<div class="cp-empty__hint">Create your first function (e.g. <code>hello-platform</code>) — every function ships as a versioned, audited module.</div></div>';
        } else {
            $grid .= '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                .'<th>Name</th><th>Auth</th><th>Methods</th><th class="cp-num">Version</th><th>Status</th><th>Updated</th><th></th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }
        $grid .= '<details class="cp-details"><summary>Safe runtime model</summary>'
            .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Versioned modules run by bounded executors '
            .'(static response · parameterized DB lookup · JSON transform). No arbitrary code, no host access, no Docker socket. '
            .'Secrets referenced as <code>{{secrets.NAME}}</code> are injected server-side and redacted from logs.</p></details>';

        return $schema->components([
            Section::make('Server Functions')->schema([Html::make($grid)])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'functions.deploy')) {
            return [];
        }

        return [
            Action::make('new_function')->label('New function')->icon('heroicon-o-plus')
                ->slideOver()
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('slug')->required()->regex('/^[a-z0-9][a-z0-9\-]{1,118}$/')
                        ->helperText('URL slug, e.g. hello-platform.'),
                    Textarea::make('description')->rows(2),
                    Select::make('type')->required()->options(FunctionRunner::types())->default('static'),
                    Select::make('auth_mode')->required()->options([
                        'key' => 'Project API key', 'public' => 'Public',
                        'user' => 'Authenticated user', 'internal' => 'Internal (owner only)',
                    ])->default('key'),
                    Select::make('methods')->multiple()->options([
                        'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE',
                    ])->default(['GET']),
                    TextInput::make('timeout_s')->numeric()->default(10),
                    TextInput::make('rate_limit_per_min')->numeric()->default(60),
                    Toggle::make('enabled')->default(true),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'functions.deploy');
                    $fn = ProjectFunction::create([
                        'project_id' => $this->project()->id,
                        'name' => $data['name'],
                        'slug' => $data['slug'],
                        'description' => $data['description'] ?? null,
                        'type' => $data['type'],
                        'enabled' => (bool) ($data['enabled'] ?? true),
                        'methods' => array_values($data['methods'] ?? ['GET']),
                        'auth_mode' => $data['auth_mode'],
                        'timeout_s' => (int) ($data['timeout_s'] ?? 10),
                        'rate_limit_per_min' => (int) ($data['rate_limit_per_min'] ?? 60),
                    ]);
                    // v1 skeleton per type so every function is invocable immediately.
                    FunctionRunner::deploy($fn, self::skeleton($data['type'], $data['slug']), $data['type']);
                    $this->audit('FUNCTION_CREATED', 'function', $fn->id, ['slug' => $fn->slug]);
                    Notification::make()->title("Function {$fn->slug} created (v1 deployed)")->success()->send();
                    $this->redirect(ProjectFunctionEditor::getUrl(['record' => $this->project(), 'fn' => $fn->id]));
                }),
        ];
    }

    public static function skeleton(string $type, string $slug): array
    {
        return match ($type) {
            'db_lookup' => ['table' => 'users', 'columns' => ['id'], 'key_column' => 'id', 'key_from' => 'query.key', 'limit' => 10],
            'transform' => ['pick' => [], 'rename' => [], 'passthrough' => true],
            default => ['status' => 200, 'body' => ['hello' => $slug, 'via' => '{{input.method}}']],
        };
    }
}
