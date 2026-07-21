<?php

namespace App\WebSocket;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Facades\Log;

/**
 * WebSocket 进程内的可开关日志。
 *
 * Workerman 长驻内存，不能依赖 Setting 单例的请求内缓存（会粘住旧值）。
 * 这里按固定间隔直读 DB，使管理后台开关能在数秒内生效。
 *
 * 默认关闭，避免节点规模较大时 Full sync / connect 类日志刷屏。
 * warning 级别始终写出（异常路径）。
 */
final class WsLog
{
    private const REFRESH_SECONDS = 5;

    private static ?int $checkedAt = null;

    private static bool $enabled = false;

    public static function enabled(): bool
    {
        $now = time();
        if (self::$checkedAt !== null && ($now - self::$checkedAt) < self::REFRESH_SECONDS) {
            return self::$enabled;
        }

        self::$checkedAt = $now;

        try {
            $raw = SettingModel::query()
                ->where('name', 'server_ws_log_enable')
                ->value('value');
            self::$enabled = self::toBool($raw);
        } catch (\Throwable) {
            // DB 不可用时保持默认关闭，避免日志风暴
            self::$enabled = false;
        }

        return self::$enabled;
    }

    public static function debug(string $message, array $context = []): void
    {
        if (self::enabled()) {
            Log::debug($message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        if (self::enabled()) {
            Log::info($message, $context);
        }
    }

    /**
     * 异常路径始终记录，不受开关影响。
     */
    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    private static function toBool(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '' || $trim === '0' || strcasecmp($trim, 'false') === 0) {
                return false;
            }
            if ($trim === '1' || strcasecmp($trim, 'true') === 0) {
                return true;
            }
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (bool) $decoded;
            }

            return (bool) filter_var($trim, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $raw;
    }
}
