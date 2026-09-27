<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Http\Controllers\SignedDownloadController;
use App\Models\StorageBucketSetting;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

/**
 * Phase 20L Storage Studio: bucket cards with usage, breadcrumb folder
 * navigation, search + sort, safe preview, signed URLs, per-bucket policy
 * (visibility, max size, MIME allowlist) enforced on upload.
 * Confinement (realpath guard) unchanged — see ProjectStorageManager.
 */
class ProjectStorage extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'bucket')]
    public ?string $bucket = null;

    #[Url(as: 'prefix')]
    public string $prefix = '';

    #[Url(as: 'q')]
    public ?string $search = null;

    #[Url(as: 'sort')]
    public ?string $sort = null;

    #[Url(as: 'preview')]
    public ?string $preview = null;

    #[Url(as: 'share')]
    public ?string $share = null;

    #[Url(as: 'ttl')]
    public ?int $ttl = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->bucket ??= request()->query('bucket');
        $this->prefix = (string) (request()->query('prefix', ''));
        $this->search ??= request()->query('q');
        $this->sort ??= request()->query('sort', 'name');
        $this->preview ??= request()->query('preview');
        $this->share ??= request()->query('share');
        $this->ttl ??= request()->query('ttl') !== null ? (int) request()->query('ttl') : null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Storage';
    }

    public function getBreadcrumbs(): array
    {
        return ['Storage'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    protected function canManage(): bool
    {
        return CpAccess::allows(auth()->user(), 'storage.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('storage'), EmbeddedSchema::make('infolist')]);
    }

    protected function storage(): ProjectStorageManager
    {
        return ProjectStorageManager::for($this->project());
    }

    protected function settings(string $bucket): ?StorageBucketSetting
    {
        return self::settingsFor($this->project(), $bucket);
    }

    public static function settingsFor(\App\Models\Project $project, string $bucket): ?StorageBucketSetting
    {
        return StorageBucketSetting::query()
            ->where('project_id', $project->id)->where('bucket', $bucket)->first();
    }

    public function infolist(Schema $schema): Schema
    {
        $storage = $this->storage();

        if (! $this->bucket) {
            $buckets = $storage->buckets();
            $totalFiles = array_sum(array_column($buckets, 'files'));
            $totalBytes = array_sum(array_column($buckets, 'bytes'));
            $rows = '';
            foreach ($buckets as $b) {
                $open = static::getUrl(['record' => $this->project(), 'bucket' => $b['name']]);
                $settings = $this->settings($b['name']);
                $policy = $settings
                    ? e($settings->visibility).' · ≤'.(int) $settings->max_size_mb.' MB'
                    : e($b['visibility']).' · default policy';
                $rows .= '<tr><td><a href="'.e($open).'"><strong>'.e($b['name']).'</strong></a></td>'
                    .'<td>'.$policy.'</td>'
                    .'<td class="cp-num">'.number_format($b['files']).'</td>'
                    .'<td class="cp-num">'.$this->bytes($b['bytes']).'</td>'
                    .'<td><a href="'.e($open).'">Open →</a></td></tr>';
            }
            $grid = '<div class="cp-toolbar"><span class="cp-toolbar__count">'.count($buckets)
                .' buckets · '.number_format($totalFiles).' files · '.$this->bytes($totalBytes).'</span></div>';
            if ($rows === '') {
                $grid .= '<div class="cp-empty"><div class="cp-empty__icon">🪣</div>'
                    .'<div class="cp-empty__title">No buckets yet</div>'
                    .'<div class="cp-empty__hint">Create your first bucket to store project files.</div>'
                    .($this->canManage() ? '<div class="cp-empty__hint">Use <strong>New bucket</strong> above.</div>' : '')
                    .'</div>';
            } else {
                $grid .= '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>Bucket</th><th>Policy</th><th class="cp-num">Files</th><th class="cp-num">Size</th><th></th>'
                    .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
            }

            return $schema->components([
                Section::make('Buckets')->schema([Html::make($grid)])
                    ->headerActions($this->bucketActions()),
            ]);
        }

        try {
            $allFiles = $storage->files($this->bucket, $this->prefix);
        } catch (\Throwable) {
            return $schema->components([Section::make('Unknown bucket or folder')]);
        }

        $q = mb_strtolower($this->search ?? '');
        $files = array_values(array_filter($allFiles, fn ($f) => $q === '' || str_contains(mb_strtolower($f['name']), $q)));
        $sort = in_array($this->sort, ['name', '-name', 'size', '-size', 'modified', '-modified'], true) ? $this->sort : 'name';
        usort($files, function ($a, $b) use ($sort) {
            $dir = str_starts_with($sort, '-') ? -1 : 1;
            $key = ltrim($sort, '-');
            if ($key === 'size') {
                return $dir * (($a['size'] ?? -1) <=> ($b['size'] ?? -1));
            }
            if ($key === 'modified') {
                return $dir * strcmp($a['modified'] ?? '', $b['modified'] ?? '');
            }

            // Folders first, then name.
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }

            return $dir * strcasecmp($a['name'], $b['name']);
        });
        $capped = count($files) > 500;
        $files = array_slice($files, 0, 500);

        // Bucket switch strip: the file browser keeps every bucket one click away.
        try {
            $bucketList = $storage->buckets();
        } catch (\Throwable) {
            $bucketList = [];
        }
        $bucketNav = '';
        if ($bucketList !== []) {
            $bucketNav = '<div class="cp-qa" style="margin-bottom:.5rem" aria-label="Buckets">';
            foreach ($bucketList as $b) {
                $bucketNav .= '<a class="cp-qa__btn"'
                    .($b['name'] === $this->bucket ? ' aria-current="true"' : '')
                    .' href="'.e(static::getUrl(['record' => $this->project(), 'bucket' => $b['name']])).'">'.e($b['name']).'</a>';
            }
            $bucketNav .= '</div>';
        }

        $crumbs = '<a href="'.e(static::getUrl(['record' => $this->project()])).'">Buckets</a> / '
            .'<a href="'.e(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket])).'">'.e($this->bucket).'</a>';
        $accum = '';
        foreach (explode('/', trim($this->prefix, '/')) as $seg) {
            if ($seg === '') {
                continue;
            }
            $accum = ltrim($accum.'/'.$seg, '/');
            $crumbs .= ' / <a href="'.e(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $accum])).'">'.e($seg).'</a>';
        }

        $self = fn (array $extra) => static::getUrl(array_merge(
            ['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix ?: null, 'q' => $this->search ?: null],
            $extra
        ));
        $sortLink = fn (string $key, string $label) => '<a href="'.e($self(['sort' => $this->sort === $key ? '-'.$key : $key])).'">'
            .$label.($this->sort === $key ? ' ▲' : ($this->sort === '-'.$key ? ' ▼' : '')).'</a>';

        $rows = '';
        foreach ($files as $f) {
            if ($f['type'] === 'folder') {
                $url = static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $f['path']]);
                $rows .= '<tr><td>📁 <a href="'.e($url).'">'.e($f['name']).'</a></td><td>folder</td>'
                    .'<td>—</td><td>—</td><td>'.e($f['modified'] ?? '—').'</td><td><a href="'.e($url).'">Open →</a></td></tr>';
                continue;
            }
            $dl = route('control-plane.download', ['project' => $this->project()->slug, 'bucket' => $this->bucket, 'key' => $f['path']]);
            $rows .= '<tr><td>'.e($f['name']).'</td><td>'.e($f['mime'] ?? 'file').'</td>'
                .'<td class="cp-num">'.($f['size'] === null ? '—' : $this->bytes($f['size'])).'</td>'
                .'<td>'.e($f['mime'] ?? '—').'</td><td>'.e($f['modified'] ?? '—').'</td>'
                .'<td><a href="'.e($dl).'">Download</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6"><div class="cp-empty"><div class="cp-empty__icon">📁</div>'
                .'<div class="cp-empty__title">Empty folder</div>'
                .'<div class="cp-empty__hint">Upload a file to get started.</div></div></td></tr>';
        }
        $grid = $bucketNav.'<div class="cp-toolbar"><span style="font-size:.8125rem">'.$crumbs.'</span>'
            .'<span class="cp-toolbar__count">'.count($allFiles).' items'.($capped ? ', showing 500' : '').'</span></div>'
            .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>'.$sortLink('name', 'Name').'</th><th>Type</th><th class="cp-num">'.$sortLink('size', 'Size').'</th>'
            .'<th>MIME</th><th>'.$sortLink('modified', 'Modified').'</th><th></th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';

        return $schema->components([
            Section::make('Files')->schema([Html::make($grid)])
                ->headerActions($this->fileActions($files)),
            ...$this->previewShareSections(),
        ]);
    }

    /** @return list<Section> */
    protected function previewShareSections(): array
    {
        $sections = [];
        if ($this->preview && $this->bucket) {
            try {
                $file = $this->storage()->read($this->bucket, $this->preview);
                $mime = $file['mime'] ?? '';
                if (str_starts_with($mime, 'image/')) {
                    $signed = SignedDownloadController::url($this->project(), $this->bucket, $this->preview, 600);
                    $body = '<img src="'.e($signed).'" style="max-width:100%;border-radius:.5rem" alt="preview">';
                } else {
                    $text = mb_substr((string) $file['contents'], 0, 20000);
                    $body = (! mb_check_encoding($text, 'UTF-8') || str_contains($mime, 'octet-stream'))
                        ? '<div class="cp-empty__hint">Binary file — download to inspect.</div>'
                        : '<div class="cp-code">'.e($text).'</div>';
                }
            } catch (\Throwable) {
                $body = '<div class="cp-empty__hint">Preview unavailable.</div>';
            }
            // Preview renders as a right drawer, not a stacked card (Phase 20.7).
            $close = static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket,
                'prefix' => $this->prefix ?: null, 'q' => $this->search ?: null, 'sort' => $this->sort]);
            $sections[] = Html::make('<div class="cp-drawer" data-open="true" role="dialog" aria-label="File preview">'
                .'<a class="cp-drawer__close" href="'.e($close).'">Close ✕</a>'
                .'<div class="cp-drawer__head"><h3 class="cp-drawer__title">'.e($this->preview).'</h3></div>'
                .'<div class="cp-drawer__body">'.$body.'</div></div>');
        }
        if ($this->share && $this->bucket) {
            $url = SignedDownloadController::url($this->project(), $this->bucket, $this->share, (int) ($this->ttl ?: 3600));
            $sections[] = Section::make('Signed URL · '.$this->share)->schema([
                Html::make('<p style="font-size:.75rem">Copy now — anyone with this link can download until it expires.</p>'
                    .'<div class="cp-code" style="white-space:pre-wrap;word-break:break-all">'.e($url).'</div>'),
            ])->compact();
        }

        return $sections;
    }

    /** @return list<Action> */
    protected function bucketActions(): array
    {
        if (! $this->canManage()) {
            return [];
        }

        return [
            Action::make('create_bucket')->label('New bucket')
                ->schema([
                    TextInput::make('name')->required()->regex('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/'),
                    Select::make('visibility')->options(['private' => 'Private', 'public' => 'Public'])->default('private'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'storage.manage');
                    $this->storage()->createBucket($data['name'], $data['visibility']);
                    $this->audit('STORAGE_BUCKET_CREATED', 'bucket', $data['name'], ['visibility' => $data['visibility']]);
                    Notification::make()->title('Bucket created')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }

    /** @return list<Action> */
    protected function fileActions(array $files): array
    {
        $actions = [
            Action::make('up')->label('Up')->visible(fn () => $this->prefix !== '')
                ->url(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->parentPrefix()])),
            Action::make('back')->label('Buckets')->url(static::getUrl(['record' => $this->project()])),
            Action::make('search')->label('Search')
                ->schema([TextInput::make('q')->label('Filename contains')->default($this->search)])
                ->action(function (array $data) {
                    $this->redirect(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix, 'q' => $data['q'] ?: null]));
                }),
        ];
        if (! $this->canManage()) {
            return $actions;
        }

        $fileOptions = [];
        foreach ($files as $f) {
            if ($f['type'] === 'file') {
                $fileOptions[$f['path']] = $f['path'];
            }
        }

        $actions[] = Action::make('upload')->label('Upload')
            ->schema([FileUpload::make('file')->required()->maxSize(24 * 1024)->storeFiles(false)])
            ->action(function (array $data) {
                CpAccess::require(auth()->user(), 'storage.manage');
                $uploaded = $data['file'];
                $path = is_array($uploaded) ? reset($uploaded) : $uploaded;
                $tmp = Storage::disk('local')->path($path);
                $contents = file_get_contents($tmp);
                $this->enforcePolicy($this->bucket, $tmp, strlen($contents));
                $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', basename($path));
                $dest = $this->storage()->store($this->bucket, $this->prefix, $name, $contents);
                @unlink($tmp);
                $this->audit('STORAGE_FILE_UPLOADED', 'file', $dest);
                Notification::make()->title('Uploaded')->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix]));
            });

        if ($fileOptions !== []) {
            $self = ['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix ?: null];
            $actions[] = Action::make('preview')->label('Preview')
                ->schema([Select::make('file')->label('File')->options($fileOptions)->required()->searchable()])
                ->action(function (array $data) use ($self) {
                    $this->redirect(static::getUrl($self + ['preview' => $data['file'], 'share' => null]));
                });
            $actions[] = Action::make('signed_url')->label('Signed URL')
                ->schema([
                    Select::make('file')->label('File')->options($fileOptions)->required()->searchable(),
                    Select::make('ttl')->label('Expires in')->options(['300' => '5 minutes', '3600' => '1 hour', '86400' => '24 hours'])->default('3600'),
                ])
                ->action(function (array $data) use ($self) {
                    CpAccess::require(auth()->user(), 'storage.manage');
                    $this->redirect(static::getUrl($self + ['share' => $data['file'], 'ttl' => $data['ttl'], 'preview' => null]));
                });
            $actions[] = Action::make('move')->label('Move / rename')
                ->schema([
                    Select::make('file')->label('File')->options($fileOptions)->required()->searchable(),
                    TextInput::make('folder')->label('Target folder (empty = bucket root)')->default($this->prefix),
                    TextInput::make('name')->label('New name')->required()->regex('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'storage.manage');
                    $dest = $this->storage()->move($this->bucket, $data['file'], trim($data['folder'], '/'), $data['name']);
                    $this->audit('STORAGE_FILE_MOVED', 'file', $data['file'], ['to' => $dest]);
                    Notification::make()->title('Moved')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix]));
                });
            $actions[] = Action::make('delete')->label('Delete')->color('danger')
                ->schema([Select::make('file')->label('File')->options($fileOptions)->required()])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'storage.manage');
                    $this->storage()->delete($this->bucket, $data['file']);
                    $this->audit('STORAGE_FILE_DELETED', 'file', $data['file']);
                    Notification::make()->title('Deleted')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix]));
                });
        }

        $actions[] = Action::make('bucket_settings')->label('Bucket policy')
            ->schema([
                Select::make('visibility')->options(['private' => 'Private', 'public' => 'Public'])->default('private'),
                TextInput::make('max_size_mb')->label('Max file size (MB)')->numeric()->default(25)->minValue(1)->maxValue(1024),
                TextInput::make('allowed_mimes')->label('Allowed MIME prefixes (comma separated, empty = all)')
                    ->placeholder('image/, text/, application/pdf')->maxLength(500),
            ])
            ->fillForm(fn () => ($s = $this->settings($this->bucket ?? '')) ? [
                'visibility' => $s->visibility, 'max_size_mb' => $s->max_size_mb,
                'allowed_mimes' => implode(', ', $s->allowed_mimes ?? []),
            ] : [])
            ->action(function (array $data) {
                CpAccess::require(auth()->user(), 'storage.manage');
                $mimes = array_values(array_filter(array_map('trim', explode(',', (string) ($data['allowed_mimes'] ?? '')))));
                StorageBucketSetting::updateOrCreate(
                    ['project_id' => $this->project()->id, 'bucket' => $this->bucket],
                    ['visibility' => $data['visibility'], 'max_size_mb' => (int) $data['max_size_mb'], 'allowed_mimes' => $mimes ?: null]
                );
                Notification::make()->title('Bucket policy saved')->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project(), 'bucket' => $this->bucket, 'prefix' => $this->prefix]));
            });

        $actions[] = Action::make('delete_bucket')->label('Delete bucket')->color('danger')
            ->requiresConfirmation()->modalDescription('Deletes the bucket and ALL files inside.')
            ->action(function () {
                CpAccess::require(auth()->user(), 'storage.manage');
                $this->storage()->deleteBucket($this->bucket);
                $this->audit('STORAGE_BUCKET_DELETED', 'bucket', $this->bucket);
                Notification::make()->title('Bucket deleted')->success()->send();
                $this->redirect(static::getUrl(['record' => $this->project()]));
            });

        return $actions;
    }

    protected function enforcePolicy(string $bucket, string $tmpPath, int $bytes): void
    {
        $this->enforcePolicyFor($this->project(), $bucket, $tmpPath, $bytes);
    }

    public static function enforcePolicyFor(\App\Models\Project $project, string $bucket, string $tmpPath, int $bytes): void
    {
        $settings = self::settingsFor($project, $bucket);
        if (! $settings) {
            return;
        }
        abort_if($bytes > $settings->max_size_mb * 1024 * 1024, 422, 'File exceeds the bucket policy limit.');
        if ($settings->allowed_mimes) {
            $mime = mime_content_type($tmpPath) ?: 'application/octet-stream';
            $ok = false;
            foreach ($settings->allowed_mimes as $prefix) {
                if (str_starts_with($mime, rtrim($prefix, '*'))) {
                    $ok = true;
                    break;
                }
            }
            abort_unless($ok, 422, "MIME type {$mime} is not allowed by bucket policy.");
        }
    }

    protected function parentPrefix(): string
    {
        $parts = explode('/', trim($this->prefix, '/'));
        array_pop($parts);

        return implode('/', $parts);
    }

    protected function bytes(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) {
            if ($b < 1024) {
                return round($b, 1).' '.$u;
            }
            $b /= 1024;
        }

        return round($b, 1).' TB';
    }
}
