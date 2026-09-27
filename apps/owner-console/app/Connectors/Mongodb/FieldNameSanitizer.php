<?php

namespace App\Connectors\Mongodb;

/**
 * Phase 28T.2 — deterministic, collision-safe field-name sanitization.
 *
 * MongoDB field names may contain '.', '$', spaces, unicode and arbitrary
 * length. PostgreSQL identifiers must match [a-z_][a-z0-9_]*. Every
 * sanitized name is derived deterministically and the ORIGINAL source path
 * is preserved in the analysis metadata (sanitized_field_map), so nothing
 * is lost by the mapping.
 */
class FieldNameSanitizer
{
    public const MAX_LENGTH = 57; // leaves room for numeric suffixes under PG's 63-byte limit

    /** Sanitized column name for a dot-path field ('address.city' → address__city). */
    public static function columnFor(string $path, array &$taken = []): string
    {
        $base = self::baseName($path);
        $candidate = $base;
        $suffix = 2;
        while (isset($taken[$candidate]) || self::collidesWithReserved($candidate, $taken)) {
            $candidate = $base.'_'.$suffix++;
        }
        $taken[$candidate] = $path;

        return $candidate;
    }

    /** Deterministic identifier form of one path (no collision suffixing). */
    public static function baseName(string $path): string
    {
        $name = str_replace('.', '__', $path);
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
                // '$', '-', unicode, symbols → _uXXXX (deterministic).
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

    /** A sanitized name must never shadow the primary key or another path's base. */
    protected static function collidesWithReserved(string $candidate, array $taken): bool
    {
        return false; // $taken already covers collisions; _id maps to itself first
    }

    /** 28N — GridFS chunk/file names are metadata only; never used as paths. */
    public static function safeMetadataName(string $name): string
    {
        return substr(preg_replace('/[\x00-\x1F\/\\\\]/', '_', $name) ?? '', 0, 255);
    }
}
