<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27C — stable connector capability vocabulary.
 *
 * Capabilities are declared by connectors (manifest + definition) and
 * reported per connector instance with an honest status. The core platform
 * only knows this vocabulary — it never knows which provider supports what.
 */
final class ConnectorCapability
{
    public const ACCOUNT_DISCOVERY = 'account_discovery';
    public const PROJECT_DISCOVERY = 'project_discovery';
    public const DATABASE_METADATA = 'database_metadata';
    public const DATA_EXTRACTION = 'data_extraction';
    public const AUTH_METADATA = 'auth_metadata';
    public const STORAGE_METADATA = 'storage_metadata';
    public const STORAGE_CONTENT = 'storage_content';
    public const FUNCTION_METADATA = 'function_metadata';
    public const POLICY_METADATA = 'policy_metadata';
    public const REALTIME_METADATA = 'realtime_metadata';
    public const SCHEDULE_METADATA = 'schedule_metadata';
    public const CLIENT_SCAN = 'client_scan';
    public const READ_ONLY_ENFORCEMENT = 'read_only_enforcement';
    public const SOURCE_FINGERPRINT = 'source_fingerprint';
    public const INCREMENTAL_EXPORT = 'incremental_export';
    public const RESUME = 'resume';

    /** The full Phase 27C vocabulary (order is display order). */
    public const ALL = [
        self::ACCOUNT_DISCOVERY,
        self::PROJECT_DISCOVERY,
        self::DATABASE_METADATA,
        self::DATA_EXTRACTION,
        self::AUTH_METADATA,
        self::STORAGE_METADATA,
        self::STORAGE_CONTENT,
        self::FUNCTION_METADATA,
        self::POLICY_METADATA,
        self::REALTIME_METADATA,
        self::SCHEDULE_METADATA,
        self::CLIENT_SCAN,
        self::READ_ONLY_ENFORCEMENT,
        self::SOURCE_FINGERPRINT,
        self::INCREMENTAL_EXPORT,
        self::RESUME,
    ];

    public const LABELS = [
        self::ACCOUNT_DISCOVERY => 'Account discovery',
        self::PROJECT_DISCOVERY => 'Project discovery',
        self::DATABASE_METADATA => 'Database metadata',
        self::DATA_EXTRACTION => 'Data extraction',
        self::AUTH_METADATA => 'Auth metadata',
        self::STORAGE_METADATA => 'Storage metadata',
        self::STORAGE_CONTENT => 'Storage content',
        self::FUNCTION_METADATA => 'Function metadata',
        self::POLICY_METADATA => 'Policy (RLS) metadata',
        self::REALTIME_METADATA => 'Realtime metadata',
        self::SCHEDULE_METADATA => 'Scheduled jobs',
        self::CLIENT_SCAN => 'Client repository scan',
        self::READ_ONLY_ENFORCEMENT => 'Read-only enforcement',
        self::SOURCE_FINGERPRINT => 'Source fingerprint',
        self::INCREMENTAL_EXPORT => 'Incremental export',
        self::RESUME => 'Resume support',
    ];

    /**
     * Phase 27C.1 — per-instance capability status. Support is never faked:
     * connectors report the honest state for the credentials they have.
     */
    public const SUPPORTED = 'SUPPORTED';
    public const SUPPORTED_WITH_CONFIGURATION = 'SUPPORTED_WITH_CONFIGURATION';
    public const PARTIAL = 'PARTIAL';
    public const NOT_SUPPORTED = 'NOT_SUPPORTED';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const STATUSES = [
        self::SUPPORTED,
        self::SUPPORTED_WITH_CONFIGURATION,
        self::PARTIAL,
        self::NOT_SUPPORTED,
        self::NOT_APPLICABLE,
    ];

    /** Human label for a capability key (unknown keys degrade gracefully). */
    public static function label(string $capability): string
    {
        return self::LABELS[$capability] ?? ucwords(str_replace('_', ' ', $capability));
    }

    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::ALL, true);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
