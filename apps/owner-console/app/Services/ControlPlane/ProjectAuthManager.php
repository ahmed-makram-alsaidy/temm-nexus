<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Supabase-Auth-like administration over a project's own Laravel auth tables.
 * Password hashes are NEVER selected, rendered, or logged.
 */
class ProjectAuthManager
{
    public const USER_SAFE_COLUMNS = ['id', 'name', 'email', 'email_verified_at', 'status', 'role', 'last_login_at', 'created_at', 'updated_at'];

    public function __construct(protected Project $project, protected string $connection) {}

    public static function for(Project $project): self
    {
        return new self($project, ProjectConnectionManager::connection($project));
    }

    public function hasUsersTable(): bool
    {
        return $this->tableExists('users');
    }

    public function tableExists(string $table): bool
    {
        return DB::connection($this->connection)->selectOne(
            "SELECT 1 AS ok FROM information_schema.tables WHERE table_schema='public' AND table_name=?",
            [$table]
        ) !== null;
    }

    public function userColumns(): array
    {
        if (! $this->hasUsersTable()) {
            return [];
        }
        $cols = DB::connection($this->connection)->getSchemaBuilder()->getColumnListing('users');

        return array_values(array_intersect(self::USER_SAFE_COLUMNS, $cols));
    }

    public function usersQuery()
    {
        $cols = $this->userColumns();

        return DB::connection($this->connection)->table('users')->select($cols === [] ? ['id'] : $cols);
    }

    public function findUser(int|string $id): ?object
    {
        if (! $this->hasUsersTable()) {
            return null;
        }
        $cols = $this->userColumns();

        return DB::connection($this->connection)->table('users')
            ->select($cols === [] ? ['id'] : $cols)->where('id', $id)->first();
    }

    public function createUser(array $data): int|string
    {
        return DB::connection($this->connection)->table('users')->insertGetId([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => ! empty($data['verified']) ? now() : null,
            ...array_filter([
                'status' => $this->hasColumn('status') ? ($data['status'] ?? 'active') : null,
                'role' => $this->hasColumn('role') ? ($data['role'] ?? 'customer') : null,
            ], fn ($v) => $v !== null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updateUser(int|string $id, array $data): int
    {
        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null);

        if ($this->hasColumn('status') && isset($data['status'])) {
            $payload['status'] = $data['status'];
        }
        if ($this->hasColumn('role') && isset($data['role'])) {
            $payload['role'] = $data['role'];
        }
        if (array_key_exists('verified', $data)) {
            $payload['email_verified_at'] = $data['verified'] ? now() : null;
        }
        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        return DB::connection($this->connection)->table('users')->where('id', $id)->limit(1)->update($payload);
    }

    public function setStatus(int|string $id, string $status): int
    {
        abort_unless($this->hasColumn('status'), 422, 'Project users table has no status column.');

        return DB::connection($this->connection)->table('users')->where('id', $id)->limit(1)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    public function isDisabled(object $user): bool
    {
        return isset($user->status) && $user->status === 'disabled';
    }

    protected function hasColumn(string $col): bool
    {
        return DB::connection($this->connection)->getSchemaBuilder()->hasColumn('users', $col);
    }

    // --- roles & permissions (project-owned tables) ---

    public function rolesQuery()
    {
        return DB::connection($this->connection)->table('roles');
    }

    public function permissionsQuery()
    {
        return DB::connection($this->connection)->table('permissions');
    }

    // --- sessions & tokens (Sanctum personal_access_tokens) ---

    public function hasTokensTable(): bool
    {
        return $this->tableExists('personal_access_tokens');
    }

    public function tokensQuery(int|string $userId)
    {
        return DB::connection($this->connection)->table('personal_access_tokens')
            ->select(['id', 'tokenable_type', 'tokenable_id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
            ->where('tokenable_id', $userId)
            ->orderByDesc('created_at');
    }

    public function revokeToken(int|string $tokenId, int|string $userId): int
    {
        return DB::connection($this->connection)->table('personal_access_tokens')
            ->where('id', $tokenId)->where('tokenable_id', $userId)->limit(1)->delete();
    }

    public function revokeAllTokens(int|string $userId): int
    {
        return DB::connection($this->connection)->table('personal_access_tokens')
            ->where('tokenable_id', $userId)->delete();
    }
}
