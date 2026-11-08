<?php

namespace Plugin\CoreProtocols;

use App\Models\Server;
use App\Models\User;
use App\Support\SudokuKey;
use App\Utils\Helper;

/**
 * 协议侧用户密码 / server_key 生成（供 ProtocolDefinition 注册使用）。
 */
class PasswordGenerators
{
    /** Shadowsocks 2022 各 cipher 的密钥长度 */
    private const SS2022_CIPHERS = [
        '2022-blake3-aes-128-gcm' => [
            'serverKeySize' => 16,
            'userKeySize' => 16,
        ],
        '2022-blake3-aes-256-gcm' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ],
        '2022-blake3-chacha20-poly1305' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ],
    ];

    /**
     * Shadowsocks：2022 cipher 返回 serverKey:userKey，其它返回 uuid。
     */
    public static function shadowsocks(Server $node, User $user): string
    {
        $cipher = data_get($node, 'protocol_settings.cipher');
        if (!$cipher || !isset(self::SS2022_CIPHERS[$cipher])) {
            return (string) $user->uuid;
        }

        $config = self::SS2022_CIPHERS[$cipher];
        $serverCreatedAt = $node->parent_id
            ? $node->parent->created_at
            : $node->created_at;
        $serverKey = Helper::getServerKey($serverCreatedAt, $config['serverKeySize']);
        $userKey = Helper::uuidToBase64($user->uuid, $config['userKeySize']);

        return "{$serverKey}:{$userKey}";
    }

    /**
     * Sudoku：由 master private + 用户 uuid 派生 Available Private Key。
     */
    public static function sudoku(Server $node, User $user): string
    {
        $settings = $node->protocol_settings ?? [];
        $masterPrivateHex = data_get($settings, 'master_private_key');
        if (!$masterPrivateHex) {
            // 无 master private 时无法派生，回退 uuid（节点鉴权会失败）
            return (string) $user->uuid;
        }

        return SudokuKey::deriveAvailablePrivateKey($masterPrivateHex, $user->uuid);
    }

    /**
     * MASQUE（双模）：返回用户 usque 设备公钥（base64 PKIX DER）。
     * 节点侧用其校验 QUIC 客户端证书（usque/mihomo 模式），sing-box
     * 订阅则将其作为 Basic 密码（服务端按同一 validator 查找）。
     * 缺失时自愈生成并持久化，保证老用户不断连。
     */
    public static function masque(Server $node, User $user): string
    {
        $pub = (string) data_get($user, 'usque_device_public', '');
        if ($pub === '') {
            try {
                $kp = \App\Utils\Helper::generateUsqueDeviceKeypair();
                $user->usque_device_private = $kp['private'];
                $user->usque_device_public = $kp['public'];
                $user->save();
                $pub = $kp['public'];
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($pub === '') {
            // 无可用密钥时回退 uuid（节点鉴权会失败，便于暴露问题）
            return (string) $user->uuid;
        }

        return $pub;
    }

    /**
     * 订阅侧 server_key（Shadowsocks 用 16 字节摘要）。
     */
    public static function shadowsocksServerKey(Server $node): ?string
    {
        return Helper::getServerKey($node->created_at, 16);
    }
}
