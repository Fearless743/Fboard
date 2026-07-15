<?php

namespace App\Support;

/**
 * Sudoku key helpers.
 *
 * Delegates scalar arithmetic to tools/sudoku-key (Go + filippo.io/edwards25519)
 * so we stay wire-compatible with official master/split keys without GMP/BCMath.
 *
 * Binary resolution order:
 * 1) env FBOARD_SUDOKU_KEY_BIN
 * 2) base_path('bin/sudoku-key')
 * 3) base_path('tools/sudoku-key/sudoku-key')
 */
class SudokuKey
{
    public static function generateMasterKeyPair(): array
    {
        $out = self::run(['-mode', 'keygen']);
        return [
            'master_private_key' => $out['master_private_key'] ?? '',
            'master_public_key' => $out['master_public_key'] ?? '',
        ];
    }

    public static function deriveAvailablePrivateKey(string $masterPrivateHex, string $userUuid): string
    {
        try {
            $out = self::run([
                '-mode', 'derive',
                '-master-private', trim($masterPrivateHex),
                '-uuid', $userUuid,
            ]);
            return $out['available_private_key'] ?? $userUuid;
        } catch (\Throwable $e) {
            return $userUuid;
        }
    }

    public static function userHashFromAvailableKey(string $availablePrivateHex): string
    {
        try {
            $out = self::run(['-mode', 'userhash', '-key', trim($availablePrivateHex)]);
            return $out['user_hash'] ?? '';
        } catch (\Throwable $e) {
            $raw = @hex2bin(trim($availablePrivateHex));
            if ($raw === false || $raw === '') {
                return '';
            }
            return substr(hash('sha256', $raw), 0, 16);
        }
    }

    public static function recoverPublicKeyFromPrivate(string $privateHex): ?string
    {
        try {
            $out = self::run(['-mode', 'recover', '-key', trim($privateHex)]);
            return $out['public_key'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function run(array $args): array
    {
        $bin = self::binaryPath();
        if ($bin === null) {
            throw new \RuntimeException('sudoku-key binary not found; build Fboard/tools/sudoku-key');
        }
        $cmd = escapeshellarg($bin);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('failed to start sudoku-key');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException(trim($stderr) !== '' ? trim($stderr) : "sudoku-key exit $code");
        }
        $data = json_decode((string) $stdout, true);
        if (!is_array($data)) {
            throw new \RuntimeException('invalid sudoku-key json output');
        }
        return $data;
    }

    private static function binaryPath(): ?string
    {
        $env = getenv('FBOARD_SUDOKU_KEY_BIN');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }
        $candidates = [];
        if (function_exists('base_path')) {
            $candidates[] = base_path('bin/sudoku-key');
            $candidates[] = base_path('tools/sudoku-key/sudoku-key');
        }
        // Fallback when base_path unavailable (CLI smoke tests)
        $root = dirname(__DIR__, 2);
        $candidates[] = $root . '/bin/sudoku-key';
        $candidates[] = $root . '/tools/sudoku-key/sudoku-key';

        foreach ($candidates as $path) {
            if (is_string($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }
}
