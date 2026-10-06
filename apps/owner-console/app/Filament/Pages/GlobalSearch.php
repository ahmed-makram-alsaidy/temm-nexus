<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\ProjectSecret;
use App\Models\ProjectTask;
use App\Models\ProjectWebhook;
use App\Models\SavedSqlQuery;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Phase 20W scoped global search: projects, tables, functions, secrets
 * (names only), tasks, webhooks, saved queries. Every result group is
 * permission-filtered — unauthorized groups are silently omitted.
 * (Filament's topbar search box is left as-is; this page is the real index.)
 */
class GlobalSearch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Search';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'search';

    #[Url(as: 'q')]
    public ?string $q = null;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.search');
    }

    public function getBreadcrumbs(): array
    {
        return [__('labels.search')];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->q ??= request()->query('q');
        $this->q = $this->q !== null ? mb_substr(trim($this->q), 0, 80) : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $user = auth()->user();
        $q = $this->q ?? '';
        $groups = [];
        if ($q !== '') {
            $like = '%'.$q.'%';
            if (CpAccess::allows($user, 'projects.view')) {
                $items = [];
                foreach (Project::query()->where('name', 'like', $like)->orWhere('slug', 'like', $like)->limit(10)->get() as $p) {
                    $items[] = ['label' => $p->name.' ('.$p->slug.')', 'url' => \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $p])];
                }
                if ($items) {
                    $groups[__('labels.search_group_projects')] = $items;
                }
            }
            if (CpAccess::allows($user, 'database.read')) {
                $items = [];
                foreach (Project::query()->limit(10)->get() as $p) {
                    try {
                        foreach (ProjectDatabaseExplorer::for($p)->tables() as $t) {
                            if (stripos($t['name'], $q) !== false) {
                                $items[] = [
                                    'label' => $p->slug.' · '.$t['name'],
                                    'url' => \App\Filament\Resources\Projects\ProjectResource::getUrl(
                                        'records', ['record' => $p, 'table' => $t['name']]
                                    ),
                                ];
                            }
                            if (count($items) >= 15) {
                                break 2;
                            }
                        }
                    } catch (\Throwable) {
                    }
                }
                if ($items) {
                    $groups[__('labels.search_group_tables')] = $items;
                }
            }
            if (CpAccess::allows($user, 'functions.view')) {
                $items = [];
                foreach (ProjectFunction::query()->where('slug', 'like', $like)->orWhere('name', 'like', $like)->limit(10)->get() as $f) {
                    $project = $f->project;
                    if (! $project) {
                        continue;
                    }
                    $items[] = [
                        'label' => $project->slug.' · '.$f->slug,
                        'url' => \App\Filament\Resources\Projects\Pages\ProjectFunctionEditor::getUrl(['record' => $project, 'fn' => $f->id]),
                    ];
                }
                if ($items) {
                    $groups[__('labels.search_group_functions')] = $items;
                }
            }
            if (CpAccess::allows($user, 'secrets.manage')) {
                $items = [];
                foreach (ProjectSecret::query()->where('name', 'like', '%'.strtoupper($q).'%')->limit(10)->get() as $s) {
                    $project = $s->project;
                    if (! $project) {
                        continue;
                    }
                    // Names only — never values.
                    $items[] = [
                        'label' => $project->slug.' · '.$s->name,
                        'url' => \App\Filament\Resources\Projects\Pages\ProjectSecrets::getUrl(['record' => $project]),
                    ];
                }
                if ($items) {
                    $groups[__('labels.search_group_secrets')] = $items;
                }
            }
            foreach ([
                'tasks' => [ProjectTask::class, 'name', 'tasks.manage', 'scheduler'],
                'webhooks' => [ProjectWebhook::class, 'name', 'webhooks.manage', 'webhooks'],
                'saved_queries' => [SavedSqlQuery::class, 'name', 'sql.execute_read', 'sql'],
            ] as $groupKey => [$model, $field, $perm, $page]) {
                if (! CpAccess::allows($user, $perm)) {
                    continue;
                }
                $items = [];
                foreach ($model::query()->where($field, 'like', $like)->limit(10)->get() as $row) {
                    $project = Project::find($row->project_id);
                    if (! $project) {
                        continue;
                    }
                    $items[] = [
                        'label' => $project->slug.' · '.$row->{$field},
                        'url' => \App\Filament\Resources\Projects\ProjectResource::getUrl($page, ['record' => $project]),
                    ];
                }
                if ($items) {
                    $groups[__('labels.search_group_'.$groupKey)] = $items;
                }
            }
        }

        $html = '<form method="GET" action="" class="cp-toolbar">'
            .'<input class="cp-toolbar__search" type="search" name="q" value="'.e($q).'" placeholder="'.e(__('labels.search_placeholder')).'" autofocus>'
            .'<button class="cp-btn is-primary" type="submit">'.e(__('labels.search_action')).'</button>'
            .'<span class="cp-toolbar__count">'.e(__('labels.search_tip', ['key' => '/'])).'</span></form>';
        foreach ($groups as $label => $items) {
            $html .= '<h4 style="margin:.75rem 0 .25rem">'.e($label).' ('.count($items).')</h4><div class="cp-list">';
            foreach ($items as $item) {
                $html .= '<div class="cp-list__item"><a href="'.e($item['url']).'">'.e($item['label']).' →</a></div>';
            }
            $html .= '</div>';
        }
        if ($q !== '' && $groups === []) {
            $html .= '<div class="cp-empty"><div class="cp-empty__title">'.e(__('labels.search_no_results')).'</div>'
                .'<div class="cp-empty__hint">'.e(__('labels.search_no_results_body')).'</div></div>';
        }

        return $schema->components([
            Section::make(__('labels.search'))->schema([Html::make($html)])->compact(),
        ]);
    }
}
