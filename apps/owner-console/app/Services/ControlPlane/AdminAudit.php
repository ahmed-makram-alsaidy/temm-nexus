<?php

namespace App\Services\ControlPlane;

use App\Models\AdminAuditEntry;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;

/**
 * Single choke point for admin-action audit records. Never logs secret values —
 * callers must pass redacted metadata only.
 */
class AdminAudit
{
    public static function record(
        string $action,
        ?Project $project = null,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $metadata = []
    ): AdminAuditEntry {
        abort_unless(in_array($action, AdminAuditEntry::ACTIONS, true), 500, 'Unknown audit action.');

        return AdminAuditEntry::create([
            'owner_user_id' => Auth::id(),
            'project_id' => $project?->id,
            'project_slug' => $project?->slug,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'ip' => request()->ip(),
            'metadata' => $metadata,
        ]);
    }
}
