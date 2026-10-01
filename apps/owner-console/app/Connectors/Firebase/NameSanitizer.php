<?php

namespace App\Connectors\Firebase;

/**
 * Phase 29A — deterministic, collision-safe field/collection-name
 * sanitization for Firebase sources.
 *
 * Firestore field paths may contain '.', '/', unicode and arbitrary length;
 * document IDs may contain nearly anything. PostgreSQL identifiers must match
 * [a-z_][a-z0-9_]*. Every sanitized name is derived deterministically and the
 * ORIGINAL source path is preserved in the analysis metadata
 * (sanitized_field_map), so nothing is lost by the mapping.
 */
class NameSanitizer
{
    public const MAX_LENGTH = 57; // leaves room for numeric suffixes under PG's 63-byte limit

    /** Sanitized column name for a dot-path field ('address.city' → address__city). */
    public static function columnFor(string $path, array &$taken = []): string
    {
        $base = self::baseName($path);
        $candidate = $base;
        $suffix = 2;
        while (isset($taken[$candidate])) {
            $candidate = $base.'_'.$suffix++;
        }
        $taken[$candidate] = $path;

        return $candidate;
    }

    /** Deterministic identifier form of one path segment or dot-path. */
    public static function baseName(string $path): string
    {
        $name = str_replace(['.', '/'], '__', $path);
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

    /** Safe metadata name (index/bucket names) — control chars and slashes removed. */
    public static function safeMetadataName(string $name): string
    {
        return substr(preg_replace('/[\x00-\x1F\/\\\\]/', '_', $name) ?? '', 0, 255);
    }

    /**
     * Sanitized schema name for the Firestore database id. Firestore database
     * ids like "(default)" normalize to a clean identifier ('default').
     */
    public static function schemaName(string $databaseId): string
    {
        return self::baseName(str_replace(['(', ')'], '', $databaseId)) ?: 'firestore';
    }

    /** Sanitized table name for a collection/subcollection path segment. */
    public static function tableName(string $collectionId): string
    {
        return self::baseName($collectionId);
    }
}
