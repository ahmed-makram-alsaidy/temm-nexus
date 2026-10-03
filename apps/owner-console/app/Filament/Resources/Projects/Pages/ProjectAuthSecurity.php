<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectAuthConfig;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\SecretService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 20K Auth Security: providers, password/session policy, email
 * templates with sandboxed preview. OAuth client secrets are stored in the
 * Secrets Vault (by reference) — never pasted as plain values here.
 */
class ProjectAuthSecurity extends Page
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
        return __('labels.providers');
    }

    public function getBreadcrumbs(): array
    {
        return ['Providers'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'users.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('auth-security'), EmbeddedSchema::make('infolist')]);
    }

    public function config(): ProjectAuthConfig
    {
        return ProjectAuthConfig::firstOrCreate(
            ['project_id' => $this->project()->id],
            [
                'providers' => ['email_password' => true, 'google' => false, 'apple' => false, 'github' => false],
                'password_policy' => ['min_length' => 10, 'reset_expiry_hours' => 2, 'login_rate_limit' => 5, 'verification_required' => true],
                'session_policy' => ['lifetime_hours' => 24, 'multi_device' => true],
                'email_templates' => [
                    'verify' => ['subject' => 'Verify your email', 'body' => '<h1>Welcome {{name}}</h1><p>Verify: <a href="{{link}}">{{link}}</a></p>'],
                    'reset' => ['subject' => 'Reset your password', 'body' => '<h1>Password reset</h1><p>Hi {{name}}, reset here: <a href="{{link}}">{{link}}</a> (expires in {{expiry}}).</p>'],
                    'welcome' => ['subject' => 'Welcome!', 'body' => '<h1>Welcome {{name}}</h1><p>Your account is ready.</p>'],
                ],
            ]
        );
    }

    public function infolist(Schema $schema): Schema
    {
        $cfg = $this->config();
        $providers = $cfg->providers ?? [];
        $pp = $cfg->password_policy ?? [];
        $sp = $cfg->session_policy ?? [];
        $tpl = $cfg->email_templates ?? [];

        $provRows = '';
        foreach (['email_password' => 'Email + password', 'google' => 'Google', 'apple' => 'Apple', 'github' => 'GitHub'] as $key => $label) {
            $on = ! empty($providers[$key]);
            $provRows .= '<tr><td>'.e($label).'</td><td>'
                .($on ? '<span class="cp-badge is-success">enabled</span>' : '<span class="cp-badge">off</span>')
                .'</td><td><code>'.e($providers[$key.'_secret'] ?? '—').'</code></td></tr>';
        }
        $preview = '';
        foreach (['verify' => 'Verify email', 'reset' => 'Reset password', 'welcome' => 'Welcome'] as $key => $label) {
            $subject = $tpl[$key]['subject'] ?? '';
            $body = self::renderPreview($tpl[$key]['body'] ?? '');
            $preview .= '<h4 style="margin:.5rem 0 .25rem">'.e($label).' — '.e($subject).'</h4>'
                // Sandboxed preview: scripts and same-origin access blocked.
                .'<iframe sandbox="" srcdoc="'.e($body).'" style="width:100%;height:180px;border:1px solid var(--cp-border);border-radius:.5rem;background:#fff"></iframe>';
        }

        return $schema->components([
            Section::make('Providers')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Provider</th><th>State</th><th>Client secret (vault ref)</th></tr></thead>'
                    .'<tbody>'.$provRows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">OAuth client secrets live in the Secrets Vault; only the reference name is stored here.</p>'),
            ])->compact(),
            Section::make('Password & session policy')->schema([
                Grid::make(2)->schema([
                    Html::make('<dl class="cp-kv"><dt>Min length</dt><dd>'.(int) ($pp['min_length'] ?? 10).'</dd>'
                        .'<dt>Reset expiry</dt><dd>'.(int) ($pp['reset_expiry_hours'] ?? 2).'h</dd>'
                        .'<dt>Login rate limit</dt><dd>'.(int) ($pp['login_rate_limit'] ?? 5).'/min</dd>'
                        .'<dt>Verification required</dt><dd>'.(! empty($pp['verification_required']) ? 'yes' : 'no').'</dd></dl>'),
                    Html::make('<dl class="cp-kv"><dt>Session lifetime</dt><dd>'.(int) ($sp['lifetime_hours'] ?? 24).'h</dd>'
                        .'<dt>Multi-device</dt><dd>'.(! empty($sp['multi_device']) ? 'yes' : 'no').'</dd></dl>'),
                ]),
            ])->compact(),
            Section::make('Email templates')->schema([Html::make($preview)])->compact(),
        ]);
    }

    /** Sample-data interpolation for preview only (stored templates untouched). */
    public static function renderPreview(string $body): string
    {
        $sample = ['name' => 'Ada Example', 'link' => 'https://example.test/verify?t=sample', 'expiry' => '2 hours', 'app' => 'Demo App'];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn ($m) => $sample[strtolower($m[1])] ?? '', $body);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit_providers')->label(__('labels.providers'))
                ->schema([
                    Toggle::make('providers.email_password')->label(__('labels.email_password')),
                    Toggle::make('providers.google')->label('Google'),
                    TextInput::make('providers.google_secret')->label(__('labels.google_client_secret_vault_ref_name'))
                        ->placeholder('GOOGLE_CLIENT_SECRET'),
                    Toggle::make('providers.apple')->label('Apple'),
                    TextInput::make('providers.apple_secret')->label(__('labels.apple_client_secret_vault_ref_name')),
                    Toggle::make('providers.github')->label('GitHub'),
                    TextInput::make('providers.github_secret')->label(__('labels.github_client_secret_vault_ref_name')),
                ])
                ->fillForm(['providers' => $this->config()->providers ?? []])
                ->action(function (array $data) {
                    $this->saveConfig(['providers' => $this->cleanProviders($data['providers'] ?? [])]);
                }),
            Action::make('edit_policy')->label(__('labels.password_session_policy'))
                ->schema([
                    TextInput::make('password_policy.min_length')->numeric()->minValue(8)->maxValue(64)->required(),
                    TextInput::make('password_policy.reset_expiry_hours')->numeric()->minValue(1)->maxValue(72)->required(),
                    TextInput::make('password_policy.login_rate_limit')->numeric()->minValue(1)->maxValue(60)->required(),
                    Toggle::make('password_policy.verification_required'),
                    TextInput::make('session_policy.lifetime_hours')->numeric()->minValue(1)->maxValue(720)->required(),
                    Toggle::make('session_policy.multi_device'),
                ])
                ->fillForm([
                    'password_policy' => $this->config()->password_policy ?? [],
                    'session_policy' => $this->config()->session_policy ?? [],
                ])
                ->action(fn (array $data) => $this->saveConfig($data)),
            Action::make('edit_templates')->label(__('labels.email_templates'))
                ->schema(array_merge(...array_map(
                    fn ($key, $label) => [
                        TextInput::make("email_templates.{$key}.subject")->label("{$label} subject")->required()->maxLength(160),
                        Textarea::make("email_templates.{$key}.body")->label("{$label} body (HTML, {{name}} {{link}} {{expiry}})")
                            ->rows(5)->required()->extraAttributes(['spellcheck' => 'false']),
                    ],
                    ['verify', 'reset', 'welcome'],
                    ['Verify email', 'Reset password', 'Welcome']
                )))
                ->fillForm(['email_templates' => $this->config()->email_templates ?? []])
                ->action(fn (array $data) => $this->saveConfig($data)),
        ];
    }

    protected function cleanProviders(array $providers): array
    {
        // Vault refs must be valid secret names when set; values never accepted.
        foreach (['google_secret', 'apple_secret', 'github_secret'] as $field) {
            if (! empty($providers[$field])) {
                $providers[$field] = SecretService::validateName($providers[$field]);
            }
        }

        return $providers;
    }

    protected function saveConfig(array $data): void
    {
        CpAccess::require(auth()->user(), 'users.manage');
        $cfg = $this->config();
        $cfg->fill($data)->save();
        $this->audit('PROJECT_SETTINGS_UPDATED', 'auth_config', null, ['fields' => array_keys($data)]);
        Notification::make()->title(__('labels.auth_configuration_saved'))->success()->send();
        $this->redirect(static::getUrl(['record' => $this->project()]));
    }
}
