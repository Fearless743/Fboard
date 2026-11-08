<?php

namespace Plugin\CoreProtocols;

use App\Services\Plugin\AbstractPlugin;
use App\Utils\Helper;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->registerShadowsocks();
        $this->registerVMess();
        $this->registerVLESS();
        $this->registerTrojan();
        $this->registerHysteria();
        $this->registerTUIC();
        $this->registerAnyTLS();
        $this->registerSOCKS();
        $this->registerNaive();
        $this->registerHTTP();
        $this->registerMieru();
        $this->registerShadowQUIC();
        $this->registerSudoku();
        $this->registerTrustTunnel();
        $this->registerMasque();
    }

    /**
     * @return callable(\App\Models\Server, array): array
     */
    private static function builder(string $method): callable
    {
        return [NodeConfigBuilders::class, $method];
    }

    /**
     * @return callable(\App\Models\Server, \App\Models\User): string
     */
    private static function password(string $method): callable
    {
        return [PasswordGenerators::class, $method];
    }

    private function registerShadowsocks(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::SHADOWSOCKS, 'Shadowsocks', [
            'cipher' => ['type' => 'string', 'default' => null, 'label' => '加密方式', 'options' => [
                'aes-256-gcm' => 'aes-256-gcm', 'aes-128-gcm' => 'aes-128-gcm',
                'chacha20-ietf-poly1305' => 'chacha20-ietf-poly1305',
                '2022-blake3-aes-256-gcm' => '2022-blake3-aes-256-gcm',
                '2022-blake3-aes-128-gcm' => '2022-blake3-aes-128-gcm',
                '2022-blake3-chacha20-poly1305' => '2022-blake3-chacha20-poly1305',
                'none' => 'none',
            ]],
            'obfs' => ['type' => 'string', 'default' => null, 'label' => '混淆类型', 'options' => ['http' => 'HTTP', 'tls' => 'TLS', 'websocket' => 'WebSocket']],
            'obfs_settings' => ['type' => 'array', 'default' => null, 'label' => '混淆设置'],
            'plugin' => ['type' => 'string', 'default' => null, 'label' => '插件', 'options' => [
                '' => 'None',
                'simple-obfs' => 'Simple Obfs',
                'v2ray-plugin' => 'V2Ray Plugin',
                'gost-plugin' => 'Gost Plugin',
                'shadow-tls' => 'Shadow TLS',
                'restls' => 'ResTLS',
                'kcptun' => 'KCPTun',
            ]],
            'plugin_opts' => ['type' => 'string', 'default' => null, 'label' => '插件参数'],
            ...self::getMultiplexFields(),
        ], array_merge(
            [
                'cipher' => 'required|string|in:aes-256-gcm,aes-128-gcm,chacha20-ietf-poly1305,2022-blake3-aes-256-gcm,2022-blake3-aes-128-gcm,2022-blake3-chacha20-poly1305,none',
                'obfs' => 'nullable|string|in:http,tls,websocket',
                'obfs_settings.path' => 'nullable|string',
                'obfs_settings.host' => 'nullable|string',
                'plugin' => 'nullable|string|in:simple-obfs,v2ray-plugin,gost-plugin,shadow-tls,restls,kcptun',
                'plugin_opts' => 'nullable|string',
            ],
            self::getMultiplexValidationRules(),
        ), '[ss]', self::builder('shadowsocks'), self::password('shadowsocks'), [], false, [PasswordGenerators::class, 'shadowsocksServerKey']);
    }

    private function registerVMess(): void
    {
        // v2ray 为历史别名，normalizeType 时映射到 vmess
        $this->registerProtocolDefinition(ProtocolTypes::VMESS, 'VMess', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'network' => ['type' => 'string', 'default' => null, 'label' => '传输协议', 'options' => ['tcp' => 'TCP', 'kcp' => 'KCP', 'ws' => 'WebSocket', 'http' => 'HTTP/2', 'quic' => 'QUIC', 'grpc' => 'gRPC', 'httpupgrade' => 'HTTPUpgrade']],
            'rules' => ['type' => 'array', 'default' => null, 'label' => '规则'],
            'network_settings' => ['type' => 'array', 'default' => null, 'label' => '网络设置'],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
            ...self::getMultiplexFields(),
            ...self::getUtlsFields(),
        ], array_merge(
            ['tls' => 'required|integer', 'network' => 'required|string', 'network_settings' => 'nullable|array', 'rules' => 'nullable|array'],
            self::getTlsSettingsValidationRules(),
            self::getMultiplexValidationRules(),
            self::getUtlsValidationRules(),
        ), '[vmess]', self::builder('vmess'), null, ['v2ray']);
    }

    private function registerVLESS(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::VLESS, 'VLESS', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => 'TLS', '2' => 'Reality']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
            'flow' => ['type' => 'string', 'default' => null, 'label' => '流控', 'show_when' => ['tls' => '2'], 'options' => [
                '' => '无',
                'xtls-rprx-direct' => 'xtls-rprx-direct',
                'xtls-rprx-splice' => 'xtls-rprx-splice',
                'xtls-rprx-vision' => 'xtls-rprx-vision',
            ]],
            'encryption' => ['type' => 'object', 'default' => null, 'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false, 'label' => '启用加密'],
                'encryption' => ['type' => 'string', 'default' => null, 'label' => '客户端公钥', 'show_when' => ['enabled' => 'true']],
                'decryption' => ['type' => 'string', 'default' => null, 'label' => '服务端私钥', 'show_when' => ['enabled' => 'true']],
            ], 'label' => '加密设置'],
            'network' => ['type' => 'string', 'default' => null, 'label' => '传输协议', 'options' => ['tcp' => 'TCP', 'kcp' => 'KCP', 'ws' => 'WebSocket', 'http' => 'HTTP/2', 'quic' => 'QUIC', 'grpc' => 'gRPC', 'xhttp' => 'XHTTP', 'httpupgrade' => 'HTTPUpgrade']],
            'network_settings' => ['type' => 'array', 'default' => null, 'label' => '网络设置'],
            ...self::getRealityFields(),
            ...self::getMultiplexFields(),
            ...self::getUtlsFields(),
        ], array_merge(
            ['tls' => 'required|integer|in:0,1,2', 'network' => 'required|string', 'network_settings' => 'nullable|array',
             'flow' => 'nullable|string|in:xtls-rprx-direct,xtls-rprx-splice,xtls-rprx-vision', 'encryption' => 'nullable|array', 'encryption.enabled' => 'nullable|boolean',
             'encryption.encryption' => 'nullable|string', 'encryption.decryption' => 'nullable|string'],
            self::getTlsSettingsValidationRules(),
            self::getRealityValidationRules(),
            self::getMultiplexValidationRules(),
            self::getUtlsValidationRules(),
        ), '[vless]', self::builder('vless'));
    }

    private function registerTrojan(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::TROJAN, 'Trojan', [
            'tls' => ['type' => 'integer', 'default' => 1, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => 'TLS', '2' => 'Reality']],
            'network' => ['type' => 'string', 'default' => null, 'label' => '传输协议', 'options' => ['tcp' => 'TCP', 'kcp' => 'KCP', 'ws' => 'WebSocket', 'quic' => 'QUIC', 'grpc' => 'gRPC', 'xhttp' => 'XHTTP', 'httpupgrade' => 'HTTPUpgrade']],
            'network_settings' => ['type' => 'array', 'default' => null, 'label' => '网络设置'],
            'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称', 'show_when' => ['tls' => '1']],
            'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接', 'show_when' => ['tls' => '1']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
            ...self::getRealityFields(),
            ...self::getMultiplexFields(),
            ...self::getUtlsFields(),
        ], array_merge(
            ['tls' => 'nullable|integer|in:0,1,2', 'network' => 'required|string', 'network_settings' => 'nullable|array',
             'server_name' => 'nullable|string', 'allow_insecure' => 'nullable|boolean'],
            self::getTlsSettingsValidationRules(true),
            self::getRealityValidationRules(),
            self::getMultiplexValidationRules(),
            self::getUtlsValidationRules(),
        ), '[trojan]', self::builder('trojan'));
    }

    private function registerHysteria(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::HYSTERIA, 'Hysteria', [
            'version' => ['type' => 'integer', 'default' => 2, 'label' => '版本', 'options' => ['2' => 'v2', '1' => 'v1']],
            'bandwidth' => ['type' => 'object', 'fields' => [
                'up' => ['type' => 'integer', 'default' => null, 'label' => '上行带宽'],
                'down' => ['type' => 'integer', 'default' => null, 'label' => '下行带宽'],
            ], 'label' => '带宽'],
            'obfs' => ['type' => 'object', 'fields' => [
                'open' => ['type' => 'boolean', 'default' => false, 'label' => '启用混淆'],
                'type' => ['type' => 'string', 'default' => 'salamander', 'label' => '混淆类型', 'options' => ['salamander' => 'Salamander'], 'show_when' => ['open' => 'true']],
                'password' => ['type' => 'string', 'default' => null, 'label' => '混淆密码', 'show_when' => ['open' => 'true']],
            ], 'label' => '混淆设置'],
            'tls' => ['type' => 'object', 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
            'hop_interval' => ['type' => 'integer', 'default' => null, 'label' => '跳跃间隔'],
            // Hysteria Realms：整段 URI（节点注册 + 客户端打洞）。主流订阅客户端不识别时会跳过该节点。
            'realm' => [
                'type' => 'string',
                'default' => null,
                'label' => 'Realms URI',
                'placeholder' => 'realm://token@host/name',
                'description' => '完整 Hysteria Realms URI（如 realm://token@host/name 或 realm+http://…）。填写后节点通过 Realms 注册并打洞；主流客户端订阅会跳过此节点，请用官方 hy2 客户端 YAML 连接。',
            ],
            'realm_insecure' => [
                'type' => 'boolean',
                'default' => false,
                'label' => 'Realms 跳过证书校验',
                'description' => '仅影响 Realms rendezvous HTTPS 客户端，不等于 Hy2 TLS allow_insecure。',
            ],
        ], array_merge(
            ['version' => 'required|integer', 'alpn' => 'nullable|string',
             'obfs.open' => 'nullable|boolean', 'obfs.type' => 'string|nullable', 'obfs.password' => 'string|nullable',
             'bandwidth.up' => 'nullable|integer', 'bandwidth.down' => 'nullable|integer', 'hop_interval' => 'integer|nullable',
             'realm' => 'nullable|string|max:512', 'realm_insecure' => 'nullable|boolean'],
            self::getTlsObjectValidationRules(),
        ), [1 => '[Hy]', 2 => '[Hy2]'], self::builder('hysteria'), null, ['hysteria2']);
    }

    private function registerTUIC(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::TUIC, 'TUIC', [
            'version' => ['type' => 'integer', 'default' => 5, 'label' => '版本', 'options' => ['5' => 'v5', '4' => 'v4', '3' => 'v3']],
            'congestion_control' => ['type' => 'string', 'default' => 'cubic', 'label' => '拥塞控制', 'options' => ['cubic' => 'CUBIC', 'bbr' => 'BBR', 'new_reno' => 'New Reno']],
            'alpn' => ['type' => 'array', 'default' => ['h3'], 'label' => 'ALPN', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1', 'spdy/3' => 'spdy/3', 'h1' => 'h1']],
            'udp_relay_mode' => ['type' => 'string', 'default' => 'native', 'label' => 'UDP中继模式', 'options' => ['native' => 'Native', 'quic' => 'QUIC']],
            'tls' => ['type' => 'object', 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], array_merge([
            'version' => 'nullable|integer|in:3,4,5',
            'congestion_control' => 'nullable|string|in:cubic,bbr,new_reno',
            'alpn' => 'nullable|array',
            'udp_relay_mode' => 'nullable|string|in:native,quic',
        ], self::getTlsObjectValidationRules()), '[tuic]', self::builder('tuic'));
    }

    private function registerAnyTLS(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::ANYTLS, 'AnyTLS', [
            // 与 Xray-core proxy/anytls 默认 padding scheme 一致（多行字符串，非数组）
            'padding_scheme' => [
                'type' => 'string',
                'default' => implode("\n", [
                    'stop=8',
                    '0=30-30',
                    '1=100-400',
                    '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
                    '3=9-9,500-1000',
                    '4=500-1000',
                    '5=500-1000',
                    '6=500-1000',
                    '7=500-1000',
                ]),
                'label' => '填充方案',
                'placeholder' => "stop=8\n0=30-30\n1=100-400\n...",
                'description' => '每行一条规则；勿填 JSON 数组。节点侧会原样作为多行字符串下发。',
            ],
            'tls' => ['type' => 'object', 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], array_merge(
            ['padding_scheme' => 'nullable|string'],
            self::getTlsObjectValidationRules(true),
        ), '[anytls]', self::builder('anytls'));
    }

    private function registerSOCKS(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::SOCKS, 'SOCKS', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], array_merge(['tls' => 'nullable|integer'], self::getTlsSettingsValidationRules()), '[socks]', self::builder('socks'));
    }

    private function registerNaive(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::NAIVE, 'NaïveProxy', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], array_merge(['tls' => 'required|integer'], self::getTlsSettingsValidationRules()), '[naive]', self::builder('naive'));
    }

    private function registerHTTP(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::HTTP, 'HTTP', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'alpn' => ['type' => 'array', 'default' => ['h2', 'http/1.1'], 'label' => 'ALPN (服务端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'client_alpn' => ['type' => 'array', 'default' => null, 'label' => 'ALPN (客户端)', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1']],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], array_merge(['tls' => 'required|integer'], self::getTlsSettingsValidationRules()), '[http]', self::builder('http'));
    }

    private function registerMieru(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::MIERU, 'Mieru', [
            'transport' => ['type' => 'string', 'default' => 'TCP', 'label' => '传输方式', 'options' => ['TCP' => 'TCP', 'UDP' => 'UDP']],
            'traffic_pattern' => [
                'type' => 'string',
                'default' => '',
                'label' => '流量特征伪装 (Base64)',
                'description' => '可选。官方 mieru Traffic Pattern 的 Base64 串，用于 TCP 分片 / Nonce 前缀等抗 DPI 流量整形；留空则使用默认行为。可用 mita/mieru export traffic-pattern 导出。',
                'placeholder' => '留空使用默认；或点击右侧按钮生成',
            ],
        ], [
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'string',
        ], '[mieru]', self::builder('mieru'));
    }


    private function registerShadowQUIC(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::SHADOWQUIC, 'ShadowQUIC', [
            'jls_upstream' => [
                'type' => 'string',
                'default' => null,
                'label' => 'JLS 伪装上游',
                'description' => '必填，格式 host:port，例如 www.cloudflare.com:443',
                'placeholder' => 'www.cloudflare.com:443',
            ],
            'server_name' => [
                'type' => 'string',
                'default' => null,
                'label' => 'SNI / server_name',
                'description' => '可空；为空时节点侧可从 jls_upstream 解析主机名',
            ],
            'alpn' => [
                'type' => 'array',
                'default' => ['h3'],
                'label' => 'ALPN',
                'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2', 'http/1.1' => 'http/1.1'],
            ],
            'congestion_control' => [
                'type' => 'string',
                'default' => 'bbr',
                'label' => '拥塞控制',
                'options' => ['bbr' => 'BBR', 'cubic' => 'CUBIC', 'new_reno' => 'New Reno'],
            ],
            'zero_rtt' => [
                'type' => 'boolean',
                'default' => true,
                'label' => '0-RTT',
            ],
            'udp_over_stream' => [
                'type' => 'boolean',
                'default' => false,
                'label' => 'UDP over Stream',
                'description' => '仅影响客户端订阅；服务端同时支持 datagram 与 stream 关联',
            ],
            ...self::getMultiplexFields(),
        ], array_merge(
            [
                'jls_upstream' => 'required|string',
                'server_name' => 'nullable|string',
                'alpn' => 'nullable|array',
                'congestion_control' => 'nullable|string|in:bbr,cubic,new_reno',
                'zero_rtt' => 'nullable|boolean',
                'udp_over_stream' => 'nullable|boolean',
            ],
            self::getMultiplexValidationRules(),
        ), '[shadowquic]', self::builder('shadowquic'));
    }

    private function registerSudoku(): void
    {
        $this->registerProtocolDefinition(ProtocolTypes::SUDOKU, 'Sudoku', [
            'master_public_key' => ['type' => 'string', 'default' => null, 'label' => 'Master Public Key'],
            'master_private_key' => ['type' => 'string', 'default' => null, 'label' => 'Master Private Key（仅面板保存，不下发节点）'],
            'aead_method' => ['type' => 'string', 'default' => 'chacha20-poly1305', 'label' => 'AEAD', 'options' => [
                'chacha20-poly1305' => 'ChaCha20-Poly1305',
                'aes-128-gcm' => 'AES-128-GCM',
                'none' => 'None（不推荐）',
            ]],
            'multiplex' => ['type' => 'string', 'default' => 'off', 'label' => '多路复用', 'options' => [
                'off' => 'off', 'auto' => 'auto', 'on' => 'on',
            ]],
            'fallback' => ['type' => 'string', 'default' => null, 'label' => '可疑回落地址 host:port'],
            'httpmask' => ['type' => 'object', 'fields' => [
                'disable' => ['type' => 'boolean', 'default' => false, 'label' => '禁用 HTTPMask'],
                'mode' => ['type' => 'string', 'default' => 'auto', 'label' => 'HTTPMask 模式', 'options' => [
                    'auto' => 'auto（推荐，兼容裸 TCP）', 'legacy' => 'legacy', 'stream' => 'stream', 'poll' => 'poll', 'ws' => 'ws',
                ]],
                // mihomo: 单段路径，仅 [A-Za-z0-9_-]；可用 "/aabbcc/" 形式，保存/下发时会规范化
                'path_root' => [
                    'type' => 'string',
                    'default' => null,
                    'label' => '路径前缀 (path-root)',
                    'description' => 'HTTP 隧道一级路径前缀，双方需一致。仅允许单段 [A-Za-z0-9_-]，例如 aabbcc；不可含点号 "." 或多级路径。新建节点时自动生成，可留空。',
                    'placeholder' => 'aabbcc',
                ],
            ], 'label' => 'HTTPMask'],
            'advanced' => ['type' => 'boolean', 'default' => false, 'label' => '高级选项'],
            'padding_min' => ['type' => 'integer', 'default' => 5, 'label' => '最小填充率(%)', 'show_when' => ['advanced' => 'true']],
            'padding_max' => ['type' => 'integer', 'default' => 15, 'label' => '最大填充率(%)', 'show_when' => ['advanced' => 'true']],
            'table_type' => ['type' => 'string', 'default' => 'prefer_entropy', 'label' => '表类型', 'show_when' => ['advanced' => 'true'], 'options' => [
                'prefer_entropy' => 'prefer_entropy',
                'prefer_ascii' => 'prefer_ascii',
                'up_ascii_down_entropy' => 'up_ascii_down_entropy',
                'up_entropy_down_ascii' => 'up_entropy_down_ascii',
            ]],
            'enable_pure_downlink' => ['type' => 'boolean', 'default' => true, 'label' => '纯 Sudoku 下行', 'show_when' => ['advanced' => 'true']],
            'custom_table' => ['type' => 'string', 'default' => null, 'label' => '自定义表布局', 'show_when' => ['advanced' => 'true']],
            'custom_tables' => ['type' => 'array', 'default' => [], 'label' => '自定义表布局列表', 'show_when' => ['advanced' => 'true']],
            'handshake_timeout' => ['type' => 'integer', 'default' => 5, 'label' => '握手超时(秒)', 'show_when' => ['advanced' => 'true']],
        ], [
            'master_public_key' => 'required|string',
            'master_private_key' => 'required|string',
            'aead_method' => 'nullable|string|in:chacha20-poly1305,aes-128-gcm,none',
            'multiplex' => 'nullable',
            'fallback' => 'nullable|string',
            'httpmask' => 'nullable|array',
            'httpmask.disable' => 'nullable|boolean',
            'httpmask.mode' => 'nullable|string|in:legacy,stream,poll,auto,ws',
            // 允许可选首尾斜杠；多级路径 / 非法字符由 regex 拒绝（与 mihomo Validate 一致）
            'httpmask.path_root' => ['nullable', 'string', 'regex:/^\/?[A-Za-z0-9_-]+\/?$/'],
            'advanced' => 'nullable|boolean',
            'padding_min' => 'nullable|integer|min:0|max:100',
            'padding_max' => 'nullable|integer|min:0|max:100',
            'table_type' => 'nullable|string|in:prefer_entropy,prefer_ascii,up_ascii_down_entropy,up_entropy_down_ascii',
            'enable_pure_downlink' => 'nullable|boolean',
            'custom_table' => 'nullable|string',
            'custom_tables' => 'nullable|array',
            'handshake_timeout' => 'nullable|integer|min:0',
        ], '[sudoku]', self::builder('sudoku'), self::password('sudoku'), [], true);
    }

    private function registerTrustTunnel(): void
    {
        // TrustTunnel：Xray-core proxy/trusttunnel 入站（HTTP/1.1 + HTTP/2 CONNECT + Basic 鉴权，
        // TCP 隧道 + UDP 多路复用 + _check 健康检查；ICMP 暂不支持）。强制 TLS，需配置证书。
        $this->registerProtocolDefinition(ProtocolTypes::TRUSTTUNNEL, 'TrustTunnel', [
            'hostname' => [
                'type' => 'string',
                'default' => null,
                'label' => '主机名',
                'description' => 'TLS SNI / 证书域名，也是订阅 tt:// 链接的 hostname。留空则使用节点地址。',
                'placeholder' => 'vpn.example.com',
            ],
            'upstream_protocol' => [
                'type' => 'string',
                'default' => 'http2',
                'label' => '上游协议',
                'options' => ['http2' => 'HTTP/2', 'http3' => 'HTTP/3'],
                'description' => '客户端优先使用的传输协议；HTTP/3 失败会自动回落 HTTP/2。',
            ],
            'custom_sni' => [
                'type' => 'string',
                'default' => null,
                'label' => '自定义 SNI',
                'description' => '可选。客户端 TLS 握手使用的 SNI，留空则使用主机名。',
            ],
            'has_ipv6' => [
                'type' => 'boolean',
                'default' => true,
                'label' => 'IPv6 可用',
            ],
            'anti_dpi' => [
                'type' => 'boolean',
                'default' => false,
                'label' => '反 DPI',
                'description' => '客户端 TLS ClientHello 随机前缀伪装（需客户端支持；当前 Xray 内核服务端不校验）。',
            ],
            'client_random_prefix' => [
                'type' => 'string',
                'default' => null,
                'label' => 'ClientRandom 前缀',
                'placeholder' => 'a0b0/f0f0',
                'description' => '可选，hex 前缀或 prefix/mask 格式，下发给客户端；当前 Xray 内核服务端不校验。',
            ],
            'client_random_auth_key' => [
                'type' => 'string',
                'default' => null,
                'label' => 'ClientRandom Auth Key',
                'description' => '可选，hex 格式的 SNI 绑定 auth key，下发给客户端；当前 Xray 内核服务端不校验。',
            ],
            'dns_upstreams' => [
                'type' => 'array',
                'default' => null,
                'label' => 'DNS 上游',
                'description' => '可选，客户端 DNS 上游列表（如 1.1.1.1、tls://dns.example.com）。',
            ],
            'skip_verification' => [
                'type' => 'boolean',
                'default' => false,
                'label' => '跳过证书校验',
                'description' => '客户端是否跳过服务端证书校验（自签证书测试用；生产环境请使用可信证书并保持关闭）。',
            ],
        ], [
            'hostname' => 'nullable|string|max:253',
            'upstream_protocol' => 'nullable|string|in:http2,http3',
            'custom_sni' => 'nullable|string|max:253',
            'has_ipv6' => 'nullable|boolean',
            'anti_dpi' => 'nullable|boolean',
            'client_random_prefix' => 'nullable|string|max:128',
            'client_random_auth_key' => 'nullable|string|max:128',
            'dns_upstreams' => 'nullable|array',
            'skip_verification' => 'nullable|boolean',
        ], '[tt]', self::builder('trusttunnel'));
    }

    private function registerMasque(): void
    {
        // MASQUE：Xray-core proxy/masque 入站（RFC 9484 CONNECT-IP over HTTP/3 + QUIC，
        // gVisor netstack 终结 TCP/ICMP，UDP 手动解析分发）。强制 TLS，需配置证书。
        $this->registerProtocolDefinition(ProtocolTypes::MASQUE, 'MASQUE', [
            'addresses' => [
                'type' => 'array',
                'default' => null,
                'label' => '隧道地址',
                'description' => '必填，隧道网络前缀，每种 IP 版本至多一个，如 10.0.0.1/24。前缀地址归服务端使用，其余地址分配给客户端。',
                'placeholder' => '10.0.0.1/24',
            ],
            'advertise_routes' => [
                'type' => 'array',
                'default' => null,
                'label' => '宣告路由',
                'description' => '可选，额外宣告给客户端的路由前缀；留空则宣告全流量（0.0.0.0/0 + ::/0）。',
            ],
            'path' => [
                'type' => 'string',
                'default' => '/.well-known/masque/ip/',
                'label' => '路径前缀',
                'description' => 'CONNECT-IP 资源路径前缀，需与客户端一致。',
            ],
            'mtu' => [
                'type' => 'integer',
                'default' => 1280,
                'label' => 'MTU',
            ],
            'versions' => [
                'type' => 'array',
                'default' => [3],
                'label' => 'HTTP 版本',
                'options' => ['1' => 'HTTP/1', '2' => 'HTTP/2', '3' => 'HTTP/3'],
                'description' => '当前 Xray 内核仅支持 HTTP/3（QUIC），选 1/2 暂无服务能力；sing-box 客户端默认 3 并自动回落。',
            ],
            'server_name' => [
                'type' => 'string',
                'default' => null,
                'label' => 'SNI / server_name',
                'description' => '可选，客户端 TLS SNI；留空则使用节点地址（服务端忽略 SNI）。',
            ],
            'endpoint_private_key' => [
                'type' => 'string',
                'default' => null,
                'label' => '端点私钥 (PEM)',
                'description' => '必填，ECDSA P-256 私钥 PEM。服务端据此自签 QUIC 证书；usque/mihomo 客户端 pin 对应公钥，sing-box 客户端须跳过校验。可通过 fboard:masque-keygen 生成。',
                'placeholder' => '-----BEGIN EC PRIVATE KEY-----',
            ],
            'endpoint_public_key' => [
                'type' => 'string',
                'default' => null,
                'label' => '端点公钥 (PEM)',
                'description' => '必填，与私钥配对的 PKIX 公钥 PEM。mihomo 订阅的 public-key 字段来源（自动 pin，无需跳过校验）。',
                'placeholder' => '-----BEGIN PUBLIC KEY-----',
            ],
        ], [
            'addresses' => 'required|array|min:1',
            'advertise_routes' => 'nullable|array',
            'path' => 'nullable|string|max:253',
            'mtu' => 'nullable|integer|min:1280|max:9000',
            'versions' => 'nullable|array',
            'server_name' => 'nullable|string|max:253',
            'endpoint_private_key' => 'required|string',
            'endpoint_public_key' => 'required|string',
        ], '[masque]', self::builder('masque'), self::password('masque'), [], true);
    }

    private static function getRealityFields(): array
    {
        return [
            'reality_settings' => ['type' => 'object', 'show_when' => ['tls' => '2'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'server_port' => ['type' => 'string', 'default' => null, 'label' => '服务器端口'],
                'public_key' => ['type' => 'string', 'default' => null, 'label' => '公钥'],
                'private_key' => ['type' => 'string', 'default' => null, 'label' => '私钥'],
                'short_id' => ['type' => 'string', 'default' => null, 'label' => 'Short ID'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
            ], 'label' => 'Reality设置'],
        ];
    }

    private static function getMultiplexFields(): array
    {
        return [
            'multiplex' => ['type' => 'object', 'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false, 'label' => '启用多路复用'],
                'protocol' => ['type' => 'string', 'default' => 'yamux', 'label' => '复用协议', 'options' => ['yamux' => 'Yamux', 'h2mux' => 'H2mux', 'smux' => 'SMux'], 'show_when' => ['enabled' => 'true']],
                'max_connections' => ['type' => 'integer', 'default' => null, 'label' => '最大连接数', 'show_when' => ['enabled' => 'true']],
                'padding' => ['type' => 'boolean', 'default' => false, 'label' => '填充', 'show_when' => ['enabled' => 'true']],
                'brutal' => ['type' => 'object', 'show_when' => ['enabled' => 'true'], 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => '启用Brutal'],
                    'up_mbps' => ['type' => 'integer', 'default' => null, 'label' => '上行速率(Mbps)', 'show_when' => ['enabled' => 'true']],
                    'down_mbps' => ['type' => 'integer', 'default' => null, 'label' => '下行速率(Mbps)', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'Brutal设置'],
            ], 'label' => '多路复用'],
        ];
    }

    private static function getUtlsFields(): array
    {
        // options 在 ProtocolDefinitionRegistry 加载时按系统设置动态注入；
        // 此处给默认列表，避免插件注册阶段尚未可读设置。
        return [
            'utls' => ['type' => 'object', 'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false, 'label' => '启用uTLS'],
                'fingerprint' => [
                    'type' => 'string',
                    'default' => 'chrome',
                    'label' => '指纹',
                    'options' => Helper::getUtlsFingerprintOptions(),
                    'show_when' => ['enabled' => 'true'],
                ],
            ], 'label' => 'uTLS设置'],
        ];
    }

    private static function getRealityValidationRules(): array
    {
        return [
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',
        ];
    }

    private static function getMultiplexValidationRules(): array
    {
        return [
            'multiplex.enabled' => 'nullable|boolean',
            'multiplex.protocol' => 'nullable|string|in:yamux,h2mux,smux',
            'multiplex.max_connections' => 'nullable|integer',
            'multiplex.padding' => 'nullable|boolean',
            'multiplex.brutal.enabled' => 'nullable|boolean',
            'multiplex.brutal.up_mbps' => 'nullable|integer',
            'multiplex.brutal.down_mbps' => 'nullable|integer',
        ];
    }

    private static function getUtlsValidationRules(): array
    {
        $allowed = array_keys(Helper::getUtlsFingerprintOptions());
        $in = $allowed !== [] ? implode(',', $allowed) : 'chrome';

        return [
            'utls.enabled' => 'nullable|boolean',
            // in 列表与系统设置 utls_fingerprints 同步（Registry 加载时再次刷新）
            'utls.fingerprint' => 'nullable|string|in:' . $in,
        ];
    }

    private static function getEchValidationRules(): array
    {
        return [
            'enabled' => 'nullable|boolean',
            'config' => 'nullable|string',
            'query_server_name' => 'nullable|string',
            'key' => 'nullable|string',
        ];
    }

    private static function getTlsSettingsValidationRules(bool $includeRoot = false): array
    {
        $rules = [
            'tls_settings.server_name' => 'required_if:protocol_settings.tls,1|nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'tls_settings.alpn' => 'nullable|array',
            'tls_settings.client_alpn' => 'nullable|array',
            'tls_settings.ech' => 'nullable|array',
        ];
        $echRules = [];
        foreach (self::getEchValidationRules() as $field => $rule) {
            $echRules['tls_settings.ech.' . $field] = $rule;
        }
        $result = array_merge($rules, $echRules);
        if ($includeRoot) {
            $result['tls_settings'] = 'nullable|array';
        }
        return $result;
    }

    private static function getTlsObjectValidationRules(bool $includeRoot = false): array
    {
        $rules = [
            'tls.server_name' => 'nullable|string',
            'tls.allow_insecure' => 'nullable|boolean',
            'tls.alpn' => 'nullable|array',
            'tls.client_alpn' => 'nullable|array',
            'tls.ech' => 'nullable|array',
        ];
        $echRules = [];
        foreach (self::getEchValidationRules() as $field => $rule) {
            $echRules['tls.ech.' . $field] = $rule;
        }
        $result = array_merge($rules, $echRules);
        if ($includeRoot) {
            $result['tls'] = 'nullable|array';
        }
        return $result;
    }
}
