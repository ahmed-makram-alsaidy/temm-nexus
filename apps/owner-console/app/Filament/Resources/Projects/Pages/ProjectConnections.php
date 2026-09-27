<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 20U connections & access: safe metadata, copyable templates (password
 * never rendered), runbook-guided rotation, pooling decision with live numbers.
 */
class ProjectConnections extends Page
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
        return 'Connections';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'Connections'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('connections'), EmbeddedSchema::make('infolist')]);
    }

    protected function conn(): array
    {
        try {
            $name = ProjectConnectionManager::connection($this->project());
            $cfg = config("database.connections.{$name}");
            $stats = DB::connection($name)->selectOne(
                'SELECT (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()) AS mine, '
                .'(SELECT setting::int FROM pg_settings WHERE name = \'max_connections\') AS max'
            );
        } catch (\Throwable) {
            return [];
        }

        return [
            'host' => $cfg['host'] ?? '—', 'port' => $cfg['port'] ?? '—',
            'database' => $cfg['database'] ?? '—', 'username' => $cfg['username'] ?? '—',
            'sslmode' => $cfg['sslmode'] ?? '—',
            'mine' => (int) ($stats->mine ?? 0), 'max' => (int) ($stats->max ?? 0),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $c = $this->conn();
        if ($c === []) {
            return $schema->components([static::connectionError(new \RuntimeException('unreachable'))]);
        }
        $maskedUri = "postgresql://{$c['username']}:***@{$c['host']}:{$c['port']}/{$c['database']}?sslmode={$c['sslmode']}";
        $laravelUrl = str_replace('postgresql://', 'pgsql://', $maskedUri);
        $poolVerdict = $c['max'] > 0 && $c['mine'] / max(1, $c['max']) < 0.2
            ? 'Not justified: this database uses '.$c['mine'].' of '.$c['max'].' connections. A pooler (PgBouncer) adds '
              .'operational cost for no measurable gain at this scale — revisit if sustained usage exceeds ~70% of max_connections '
              .'or per-request connect latency dominates.'
            : 'Review: connection pressure is elevated — see 20Z measurements before deciding.';

        return $schema->components([
            ...$this->rotationReveal(),
            Section::make('Connection')->schema([
                Html::make('<dl class="cp-kv">'
                    .'<dt>Host</dt><dd><code>'.e($c['host']).'</code></dd>'
                    .'<dt>Port</dt><dd><code>'.e((string) $c['port']).'</code></dd>'
                    .'<dt>Database</dt><dd><code>'.e($c['database']).'</code></dd>'
                    .'<dt>Username</dt><dd><code>'.e($c['username']).'</code></dd>'
                    .'<dt>SSL mode</dt><dd><code>'.e($c['sslmode']).'</code></dd>'
                    .'<dt>Connections</dt><dd>'.$c['mine'].' / '.$c['max'].'</dd>'
                    .'</dl><p style="font-size:.75rem;color:var(--cp-text-dim)">Existing passwords are never displayed — rotation issues a new one (shown once).</p>'),
            ])->compact(),
            Section::make('Templates')->schema([
                Html::make('<h4 style="margin:.25rem 0">PostgreSQL URI</h4><div class="cp-code">'.e($maskedUri).'</div>'
                    .'<h4 style="margin:.75rem 0 .25rem">Laravel DATABASE_URL</h4><div class="cp-code">'.e('DATABASE_URL="'.$laravelUrl.'"').'</div>'
                    .'<h4 style="margin:.75rem 0 .25rem">psql</h4><div class="cp-code">'.e("PGPASSWORD='***' psql -h {$c['host']} -p {$c['port']} -U {$c['username']} -d {$c['database']}").'</div>'),
            ])->compact(),
            Section::make('Credential rotation')->schema([
                Html::make('<p style="font-size:.8125rem">Rotation is runbook-guided: the console generates a strong password '
                    .'(shown once), the owner applies it as the project DB role password, updates the console env, and restarts '
                    .'the console service. One-click rotation is intentionally absent — the web tier cannot safely rewrite its own '
                    .'credentials and restart itself.</p>'),
            ])->headerActions([
                Action::make('generate_password')->label('Generate new password')
                    ->visible(fn () => CpAccess::allows(auth()->user(), 'settings.manage'))
                    ->requiresConfirmation()
                    ->modalDescription('Generates a 40-char password shown ONCE. Nothing changes until you apply it via the runbook.')
                    ->action(function () {
                        $plain = Str::password(40);
                        session()->flash('cp_new_db_password', $plain);
                        $this->audit('CREDENTIALS_ROTATED', 'database', null, ['stage' => 'generated']);
                        Notification::make()->title('Password generated — copy it now')->warning()->send();
                        $this->redirect(static::getUrl(['record' => $this->project(), 'rotated' => 1]));
                    }),
            ])->compact(),
            Section::make('Pooling')->schema([
                Html::make('<p style="font-size:.8125rem">'.e($poolVerdict).'</p>'),
            ])->compact(),
        ]);
    }

    /** @return list<Section> */
    protected function rotationReveal(): array
    {
        $plain = request()->query('rotated') ? session('cp_new_db_password') : null;
        if (! $plain) {
            return [];
        }

        return [Section::make('New password — copy now, shown once')->schema([
            Html::make('<div class="cp-code">'.e($plain).'</div>'
                .'<p style="font-size:.75rem">Runbook: 1) <code>ALTER USER role WITH PASSWORD \'…\'</code> as DBA · '
                .'2) update the console <code>.env</code> (<code>PROJECT_*_DB_PASSWORD</code>) · '
                .'3) restart the console service · 4) re-run the connection check from Overview.</p>'),
        ])];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
