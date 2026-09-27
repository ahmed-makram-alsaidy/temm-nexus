<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Audit-logging foundation. Attach to any model that needs a change trail:
 *
 *   use Auditable;
 *
 * Produces one AuditLog row per created/updated/deleted event with
 * actor id, changed attributes (dirty only, no secrets — filter via
 * $auditHidden on the model), and the request IP where available.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(function ($model) use ($event) {
                $changes = $event === 'updated'
                    ? $model->getChanges()
                    : $model->getAttributes();

                foreach ((array) ($model->auditHidden ?? []) as $hidden) {
                    unset($changes[$hidden]);
                }

                AuditLog::create([
                    'auditable_type' => $model::class,
                    'auditable_id' => $model->getKey(),
                    'event' => $event,
                    'actor_id' => Auth::id(),
                    'changes' => $changes,
                    'ip' => request()->ip(),
                ]);
            });
        }
    }
}
