<?php

namespace Plugin\CoreProtocols;

use App\Models\Server;
use App\Services\ServerService;
use App\Support\SudokuKey;
use App\Utils\Helper;

/**
 * 各协议节点下发配置构建器（供 ProtocolDefinition.serverConfigBuilder 使用）。
 *
 * 签名统一为：fn(Server $node, array $baseConfig): array
 * $baseConfig 已含 protocol / listen_ip / server_port / network / networkSettings / maintenance_mode。
 */
class NodeConfigBuilders
{
    /**
     * 将通用多路复用对象归一为 mihomo 字符串开关（on/off）。
     * 保留旧字段单值（'off'/'auto'/'on'）透传。
     */
    private static function mapMultiplexString(mixed $multiplex): string
    {
        if (is_array($multiplex)) {
            return !empty($multiplex['enabled']) ? 'on' : 'off';
        }
        $value = (string) $multiplex;
        return $value === 'auto' ? 'auto' : ($value === 'on' ? 'on' : 'off');
    }

    public static function shadowsocks(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'cipher' => $protocolSettings['cipher'],
            'plugin' => $protocolSettings['plugin'],
            'plugin_opts' => $protocolSettings['plugin_opts'],
            'server_key' => match ($protocolSettings['cipher']) {
                '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                default => null,
            },
            'multiplex' => data_get($protocolSettings, 'multiplex'),
        ];
    }

    public static function vmess(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'tls' => (int) $protocolSettings['tls'],
            'tls_settings' => $protocolSettings['tls_settings'],
            'multiplex' => data_get($protocolSettings, 'multiplex'),
        ];
    }

    public static function trojan(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'host' => $node->host,
            'server_name' => data_get($protocolSettings, 'tls_settings.server_name'),
            'multiplex' => data_get($protocolSettings, 'multiplex'),
            'tls' => (int) $protocolSettings['tls'],
            'tls_settings' => match ((int) $protocolSettings['tls']) {
                2 => Helper::normalizeRealitySettings($protocolSettings['reality_settings'] ?? null),
                default => $protocolSettings['tls_settings'],
            },
        ];
    }

    public static function vless(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'tls' => (int) $protocolSettings['tls'],
            'flow' => $protocolSettings['flow'],
            'decryption' => match (data_get($protocolSettings, 'encryption.enabled')) {
                true => data_get($protocolSettings, 'encryption.decryption'),
                default => null,
            },
            'tls_settings' => match ((int) $protocolSettings['tls']) {
                2 => Helper::normalizeRealitySettings($protocolSettings['reality_settings'] ?? null),
                default => $protocolSettings['tls_settings'],
            },
            'multiplex' => data_get($protocolSettings, 'multiplex'),
        ];
    }

    public static function hysteria(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        // Hy2 multi-port listen for client UDP hopping (null when single port / invalid).
        $hysteriaListenPorts = ServerService::hysteriaListenPorts($node);

        return [
            ...$baseConfig,
            'server_port' => (int) $serverPort,
            // Client port range (e.g. 10000-20000) → node multi-port listen for Hy2 hopping.
            // Subscription already exposes the same range via $server->ports; hop_interval is client-only.
            ...($hysteriaListenPorts !== null ? ['listen_ports' => $hysteriaListenPorts] : []),
            'version' => (int) ($protocolSettings['version'] ?? 2),
            'host' => $node->host,
            'server_name' => data_get($protocolSettings, 'tls.server_name', $node->host ?? ''),
            'tls_settings' => $protocolSettings['tls'] ?? [],
            'up_mbps' => (int) ($protocolSettings['bandwidth']['up'] ?? 100),
            'down_mbps' => (int) ($protocolSettings['bandwidth']['down'] ?? 100),
            // Hysteria Realms URI (realm://token@host/name); empty = ordinary Hy2.
            'realm' => filled(data_get($protocolSettings, 'realm'))
                ? trim((string) data_get($protocolSettings, 'realm'))
                : null,
            'realm_insecure' => (bool) data_get($protocolSettings, 'realm_insecure', false),
            ...match ((int) ($protocolSettings['version'] ?? 2)) {
                1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                2 => [
                    'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                    'obfs-password' => $protocolSettings['obfs']['password'] ?? null,
                ],
                default => [],
            },
        ];
    }

    public static function tuic(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'version' => (int) ($protocolSettings['version'] ?? 2),
            'server_port' => (int) $node->server_port,
            'server_name' => data_get($protocolSettings, 'tls.server_name', $node->host ?? ''),
            'congestion_control' => $protocolSettings['congestion_control'] ?? 'bbr',
            'tls_settings' => $protocolSettings['tls'] ?? [],
            'auth_timeout' => '3s',
            'zero_rtt_handshake' => false,
            'heartbeat' => '3s',
        ];
    }

    public static function anytls(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            // AnyTLS always requires TLS; expose tls=1 so node kernels enable stream TLS.
            'tls' => 1,
            'server_name' => data_get($protocolSettings, 'tls.server_name', $node->host ?? ''),
            'tls_settings' => $protocolSettings['tls'] ?? [],
            'padding_scheme' => $protocolSettings['padding_scheme'] ?? false,
        ];
    }

    public static function socks(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'tls' => (int) data_get($protocolSettings, 'tls', 0),
            'tls_settings' => data_get($protocolSettings, 'tls_settings'),
        ];
    }

    public static function naive(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'tls' => (int) $protocolSettings['tls'],
            'tls_settings' => $protocolSettings['tls_settings'],
        ];
    }

    public static function http(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'tls' => (int) $protocolSettings['tls'],
            'tls_settings' => $protocolSettings['tls_settings'],
        ];
    }

    public static function mieru(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'transport' => data_get($protocolSettings, 'transport', 'TCP'),
            'traffic_pattern' => $protocolSettings['traffic_pattern'],
            'multiplex' => data_get($protocolSettings, 'multiplex'),
        ];
    }

    public static function shadowquic(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'jls_upstream' => data_get($protocolSettings, 'jls_upstream'),
            'server_name' => data_get($protocolSettings, 'server_name'),
            'congestion_control' => data_get($protocolSettings, 'congestion_control', 'bbr'),
            'zero_rtt' => (bool) data_get($protocolSettings, 'zero_rtt', true),
            'alpn' => data_get($protocolSettings, 'alpn', ['h3']),
            'multiplex' => data_get($protocolSettings, 'multiplex'),
        ];
    }

    public static function sudoku(Server $node, array $baseConfig): array
    {
        $protocolSettings = $node->protocol_settings;

        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'server_key' => data_get($protocolSettings, 'master_public_key'),
            'sudoku_config' => [
                'aead_method' => data_get($protocolSettings, 'aead_method', 'chacha20-poly1305'),
                'padding_min' => data_get($protocolSettings, 'padding_min', 5),
                'padding_max' => data_get($protocolSettings, 'padding_max', 15),
                'table_type' => data_get($protocolSettings, 'table_type', 'prefer_entropy'),
                'enable_pure_downlink' => (bool) data_get($protocolSettings, 'enable_pure_downlink', true),
                'custom_table' => data_get($protocolSettings, 'custom_table'),
                'custom_tables' => data_get($protocolSettings, 'custom_tables', []),
                'handshake_timeout' => data_get($protocolSettings, 'handshake_timeout', 5),
                'disable_http_mask' => (bool) data_get($protocolSettings, 'httpmask.disable', false),
                'http_mask_mode' => data_get($protocolSettings, 'httpmask.mode', 'legacy'),
                'path_root' => SudokuKey::normalizeHttpMaskPathRoot(
                    data_get($protocolSettings, 'httpmask.path_root')
                ),
                'fallback' => data_get($protocolSettings, 'fallback'),
                'multiplex' => self::mapMultiplexString(data_get($protocolSettings, 'multiplex', 'off')),
            ],
        ];
    }
}
