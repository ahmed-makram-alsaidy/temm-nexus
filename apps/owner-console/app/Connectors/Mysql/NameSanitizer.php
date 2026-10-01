<?php

namespace App\Connectors\Mysql;

/**
 * Phase 31 — deterministic, collision-safe identifier sanitation for MySQL
 * sources (table/column names into PostgreSQL-safe metadata names).
 */
class NameSanitizer
{
    public const MAX_LENGTH = 57;

    /** Deterministic identifier form of one path segment. */
    public static function baseName(string $name): string
    {
        $out = '';
        $length = mb_strlen($name);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($name, $i, 1);
            if (preg_match('/[a-z0-9_]/', $char) === 1) {
                $out .= $char;
            } elseif (preg_match('/[A-Z]/', $char) === 1) {
                $out .= strtolower($char);
            } elseif ($char === ' ') {
                $out .= '_';
            } else {
                $out .= '_u'.dechex(mb_ord($char));
            }
            if (strlen($out) >= self::MAX_LENGTH) {
                break;
            }
        }
        if ($out === '' || preg_match('/^[0-9]/', $out) === 1) {
            $out = '_'.$out;
        }

        return substr($out, 0, self::MAX_LENGTH);
    }

    /** Sanitized table name. */
    public static function tableName(string $name): string
    {
        return self::baseName($name);
    }

    /** Safe metadata name (index names etc.). */
    public static function safeMetadataName(string $name): string
    {
        return substr(preg_replace('/[\x00-\x1F\/\\\\]/', '_', $name) ?? '', 0, 255);
    }
}
