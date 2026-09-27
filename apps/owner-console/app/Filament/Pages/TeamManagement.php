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

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'team';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Team';
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
            Section::make('Roles & permissions')->schema([
                Html::make(self::matrixHtml()),
            ])->compact(),
            Section::make('Control-plane users')->schema([
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
            return '<div class="cp-empty__hint">Roles not seeded yet.</div>';
        }
        $cell = function ($role, string $perm): string {
            $perms = $role->permissions ?? [];
            $has = in_array('*', $perms, true) || in_array($perm, $perms, true);

            return '<td class="cp-matrix__cell">'.($has ? '<span class="cp-badge is-success">✓</span>' : '<span style="color:var(--cp-text-faint)">—</span>').'</td>';
        };
        $html = '<div class="cp-tablewrap"><table class="cp-grid cp-matrix"><thead><tr><th class="cp-matrix__sticky">Permission</th>';
        foreach ($roles as $role) {
            $html .= '<th>'.e($role->name).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (self::PERMISSION_GROUPS as $group => $perms) {
            $html .= '<tr class="cp-matrix__group"><td class="cp-matrix__sticky" colspan="'.(count($roles) + 1).'">'.e($group).'</td></tr>';
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
            .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Infrastructure owners (is_admin) bypass every check. '
            .'High-risk permissions (secrets.manage, database.write, sql.execute_write, functions.deploy, restore.local, team.manage)'
            .' are never implied — they must be assigned explicitly.</p>';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => User::query()->orderBy('email'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('is_admin')->label('Owner login')->badge()
                    ->color(fn ($s) => $s ? 'success' : 'gray')
                    ->formatStateUsing(fn ($s) => $s ? 'yes' : 'no'),
                TextColumn::make('cp_role')->label('Team role')->badge()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('assign_role')->label('Assign role')
                    ->schema([
                        Select::make('cp_role')->label('Team role')->required()->options([
                            'owner' => 'Owner (full power — use sparingly)',
                            'admin' => 'Admin (everything except team)',
                            'developer' => 'Developer (read + invoke + tasks/webhooks/storage)',
                            'observer' => 'Observer (read-only)',
                            '' => 'No team access (revoke)',
                        ]),
                    ])
                    ->action(function (array $data, User $record) {
                        CpAccess::require(auth()->user(), 'team.manage');
                        abort_if($record->id === auth()->id(), 422, 'You cannot change your own role.');
                        $record->forceFill(['cp_role' => $data['cp_role'] ?: null])->save();
                        AdminAudit::record('TEAM_ROLE_ASSIGNED', null, 'user', $record->id, [
                            'email' => $record->email, 'cp_role' => $data['cp_role'] ?: null,
                        ]);
                        Notification::make()->title("Role updated for {$record->email}")->success()->send();
                    }),
            ])
            ->emptyStateHeading('No users');
    }
}
