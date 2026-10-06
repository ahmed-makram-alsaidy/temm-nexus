<?php

namespace App\Filament\Pages;

use App\Models\ControlPlaneRole;
use App\Models\User;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\CpAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Phase 20V team & owner RBAC: control-plane users, explicit role assignment,
 * permission matrix. Owner-only (team.manage). Infrastructure login is no
 * longer just admin/non-admin: Owner, Admin, Developer, Observer.
 */
class TeamManagement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Team';

    public static function getNavigationLabel(): string
    {
        return __('nav.team');
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'team';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.team');
    }

    public function getBreadcrumbs(): array
    {
        return ['Team'];
    }

    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'team.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make(__('infra.team_matrix_title'))->schema([
                Html::make(self::matrixHtml()),
            ])->compact(),
            Section::make(__('infra.team_users_title'))
                ->description(__('infra.team_users_description'))
                ->schema([
                    EmbeddedTable::make(),
                ]),
        ]);
    }

    /** Group key => [title, permissions] — display only; RBAC semantics unchanged. */
    public const PERMISSION_GROUPS = [
        'Projects' => ['projects.view', 'infrastructure.view'],
        'Database' => ['database.read', 'database.write'],
        'SQL' => ['sql.execute_read', 'sql.execute_write'],
        'Functions' => ['functions.view', 'functions.invoke', 'functions.deploy'],
        'Users' => ['users.manage'],
        'Storage' => ['storage.manage'],
        'Secrets' => ['secrets.manage', 'keys.manage'],
        'Backups' => ['backups.trigger', 'restore.local'],
        'Operate' => ['webhooks.manage', 'tasks.manage', 'logs.view', 'settings.manage', 'team.manage'],
    ];

    public static function matrixHtml(): string
    {
        $roles = ControlPlaneRole::query()->orderBy('id')->get();
        if ($roles->isEmpty()) {
            return '<div class="cp-empty__hint">'.e(__('infra.team_roles_not_seeded')).'</div>';
        }
        $cell = function ($role, string $perm): string {
            $perms = $role->permissions ?? [];
            $has = in_array('*', $perms, true) || in_array($perm, $perms, true);

            return '<td class="cp-matrix__cell">'.($has ? '<span class="cp-badge is-success">✓</span>' : '<span style="color:var(--cp-text-faint)">—</span>').'</td>';
        };
        $html = '<div class="cp-tablewrap"><table class="cp-grid cp-matrix"><thead><tr><th class="cp-matrix__sticky">'.e(__('infra.team_matrix_permission')).'</th>';
        foreach ($roles as $role) {
            $html .= '<th>'.e($role->name).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (self::PERMISSION_GROUPS as $group => $perms) {
            $html .= '<tr class="cp-matrix__group"><td class="cp-matrix__sticky" colspan="'.(count($roles) + 1).'">'.e(__('infra.team_group_'.\Illuminate\Support\Str::of($group)->lower())).'</td></tr>';
            foreach ($perms as $perm) {
                if (! in_array($perm, CpAccess::PERMISSIONS, true)) {
                    continue;
                }
                $html .= '<tr><td class="cp-matrix__sticky"><code>'.e($perm).'</code></td>';
                foreach ($roles as $role) {
                    $html .= $cell($role, $perm);
                }
                $html .= '</tr>';
            }
        }

        return $html.'</tbody></table></div>'
            .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('infra.team_matrix_note', [
                'perms' => 'secrets.manage, database.write, sql.execute_write, functions.deploy, restore.local, team.manage',
            ])).'</p>';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => User::query()->orderBy('email'))
            ->columns([
                TextColumn::make('name')->label(__('infra.team_col_name'))->searchable(),
                TextColumn::make('email')->label(__('infra.team_col_email'))->searchable()->copyable(),
                TextColumn::make('is_admin')->label(__('infra.team_role_owner'))->badge()
                    ->color(fn ($s) => $s ? 'success' : 'gray')
                    ->formatStateUsing(fn ($s) => $s ? __('infra.team_role_owner') : __('infra.team_role_member')),
                TextColumn::make('cp_role')->label(__('labels.team_role'))->badge()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('assign_role')->label(__('infra.team_assign_action'))
                    ->schema([
                        Select::make('cp_role')->label(__('labels.team_role'))->required()->options([
                            'owner' => __('infra.team_role_owner_option'),
                            'admin' => __('infra.team_role_admin_option'),
                            'developer' => __('infra.team_role_developer_option'),
                            'observer' => __('infra.team_role_observer_option'),
                            '' => __('infra.team_role_revoke_option'),
                        ]),
                    ])
                    ->action(function (array $data, User $record) {
                        CpAccess::require(auth()->user(), 'team.manage');
                        // Same server-side rule as before (never your own
                        // role) — surfaced as a notification instead of a
                        // bare 422 error page.
                        if ($record->id === auth()->id()) {
                            Notification::make()->title(__('infra.team_role_self_error'))->danger()->send();

                            return;
                        }
                        $record->forceFill(['cp_role' => $data['cp_role'] ?: null])->save();
                        AdminAudit::record('TEAM_ROLE_ASSIGNED', null, 'user', $record->id, [
                            'email' => $record->email, 'cp_role' => $data['cp_role'] ?: null,
                        ]);
                        Notification::make()->title(__('infra.team_role_updated', ['email' => $record->email]))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('infra.team_empty_title'))
            ->emptyStateDescription(__('infra.team_empty_body'));
    }
}
