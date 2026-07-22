<?php

namespace Plugin\CoreProtocols;

/**
 * 内置协议 type 字符串常量。
 *
 * 权威清单以本插件 registerProtocolDefinition 注册为准；
 * 此处仅供输出格式插件等引用，避免散落魔法字符串。
 * 第三方协议插件应在自身命名空间定义自己的 type 常量。
 */
final class ProtocolTypes
{
    public const SHADOWSOCKS = 'shadowsocks';
    public const VMESS = 'vmess';
    public const VLESS = 'vless';
    public const TROJAN = 'trojan';
    public const HYSTERIA = 'hysteria';
    public const TUIC = 'tuic';
    public const ANYTLS = 'anytls';
    public const SOCKS = 'socks';
    public const NAIVE = 'naive';
    public const HTTP = 'http';
    public const MIERU = 'mieru';
    public const SUDOKU = 'sudoku';
    public const SHADOWQUIC = 'shadowquic';

    /**
     * CoreProtocols 注册的全部 type（不含别名）。
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SHADOWSOCKS,
            self::VMESS,
            self::VLESS,
            self::TROJAN,
            self::HYSTERIA,
            self::TUIC,
            self::ANYTLS,
            self::SOCKS,
            self::NAIVE,
            self::HTTP,
            self::MIERU,
            self::SUDOKU,
            self::SHADOWQUIC,
        ];
    }
}
