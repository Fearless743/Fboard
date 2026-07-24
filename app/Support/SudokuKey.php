<?php

namespace App\Support;

use RuntimeException;

/**
 * Sudoku ED25519 master/split key helpers (panel-native, no external binary).
 *
 * Wire format matches official SUDOKU-ASCII/sudoku:
 * - Master private: 32-byte scalar (hex)
 * - Master public:  compressed Edwards point P = x·B (hex)
 * - Available private: 64-byte split r||k (hex) with x ≡ r+k (mod L)
 * - UserHash: hex(sha256(raw available private key bytes)[:8])
 *
 * Uses paragonie/sodium_compat Curve25519/Ed25519 field arithmetic already
 * vendored by the project (no GMP/BCMath, no external sudoku-key process).
 */
class SudokuKey
{
    /**
     * Normalize HTTPMask path-root to a single path segment.
     *
     * mihomo/Xray both require: one segment, only [A-Za-z0-9_-]
     * (optional surrounding slashes are stripped, e.g. "/aabbcc/" -> "aabbcc").
     * Returns null when empty or invalid so callers can omit the field.
     */
    public static function normalizeHttpMaskPathRoot(?string $root): ?string
    {
        if ($root === null) {
            return null;
        }

        $v = trim($root);
        $v = trim($v, '/');
        if ($v === '' || str_contains($v, '/')) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $v)) {
            return null;
        }

        return $v;
    }

    public static function deriveAvailablePrivateKey(string $masterPrivateHex, string $userUuid): string
    {
        $master = self::parseScalarBytes(trim($masterPrivateHex));
        if ($master === null) {
            return $userUuid;
        }

        // Deterministic r = reduce(SHA512("fboard-sudoku-v1" || master || uuid))
        $digest = hash('sha512', 'fboard-sudoku-v1' . $master . $userUuid, true);
        $r = self::scalarReduce($digest);
        $k = self::scalarSub($master, $r);

        return bin2hex($r . $k);
    }

    public static function userHashFromAvailableKey(string $availablePrivateHex): string
    {
        $raw = @hex2bin(trim($availablePrivateHex));
        if ($raw === false || $raw === '') {
            return '';
        }

        return substr(hash('sha256', $raw), 0, 16);
    }

    public static function recoverPublicKeyFromPrivate(string $privateHex): ?string
    {
        $scalar = self::parseScalarBytes(trim($privateHex));
        if ($scalar === null) {
            return null;
        }

        return bin2hex(self::scalarBaseMult($scalar));
    }

    private static function parseScalarBytes(string $hex): ?string
    {
        $raw = @hex2bin($hex);
        if ($raw === false) {
            return null;
        }
        if (strlen($raw) === 32) {
            return $raw;
        }
        if (strlen($raw) === 64) {
            // split r||k -> r+k
            return self::scalarAdd(substr($raw, 0, 32), substr($raw, 32, 32));
        }

        return null;
    }

    /** reduce 64-byte little-endian integer mod L -> 32-byte scalar */
    private static function scalarReduce(string $bytes64): string
    {
        if (strlen($bytes64) < 64) {
            $bytes64 = str_pad($bytes64, 64, "\0");
        } elseif (strlen($bytes64) > 64) {
            $bytes64 = substr($bytes64, 0, 64);
        }

        return \ParagonIE_Sodium_Core_Ed25519::sc_reduce($bytes64);
    }

    private static function scalarAdd(string $a, string $b): string
    {
        return \ParagonIE_Sodium_Core_Ed25519::scalar_add($a, $b);
    }

    private static function scalarSub(string $a, string $b): string
    {
        return \ParagonIE_Sodium_Core_Ed25519::scalar_sub($a, $b);
    }

    /** P = x * B (no clamp; x is already a canonical scalar) */
    private static function scalarBaseMult(string $scalar32): string
    {
        if (strlen($scalar32) !== 32) {
            throw new RuntimeException('sudoku scalar must be 32 bytes');
        }

        $p3 = \ParagonIE_Sodium_Core_Ed25519::ge_scalarmult_base($scalar32);

        return \ParagonIE_Sodium_Core_Ed25519::ge_p3_tobytes($p3);
    }
}
