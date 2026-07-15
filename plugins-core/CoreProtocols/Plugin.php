<?php

namespace Plugin\CoreProtocols;

use App\Services\Plugin\AbstractPlugin;

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
    }

    private function registerShadowsocks(): void
    {
        $this->registerProtocolDefinition('shadowsocks', 'Shadowsocks', [
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
        ], [
            'cipher' => 'required|string|in:aes-256-gcm,aes-128-gcm,chacha20-ietf-poly1305,2022-blake3-aes-256-gcm,2022-blake3-aes-128-gcm,2022-blake3-chacha20-poly1305,none',
            'obfs' => 'nullable|string|in:http,tls,websocket',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string|in:simple-obfs,v2ray-plugin,gost-plugin,shadow-tls,restls,kcptun',
            'plugin_opts' => 'nullable|string',
        ], '[ss]');
    }

    private function registerVMess(): void
    {
        $this->registerProtocolDefinition('vmess', 'VMess', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'network' => ['type' => 'string', 'default' => null, 'label' => '传输协议', 'options' => ['tcp' => 'TCP', 'kcp' => 'KCP', 'ws' => 'WebSocket', 'http' => 'HTTP/2', 'quic' => 'QUIC', 'grpc' => 'gRPC']],
            'rules' => ['type' => 'array', 'default' => null, 'label' => '规则'],
            'network_settings' => ['type' => 'array', 'default' => null, 'label' => '网络设置'],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
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
        ), '[vmess]');
    }

    private function registerVLESS(): void
    {
        $this->registerProtocolDefinition('vless', 'VLESS', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => 'TLS', '2' => 'Reality']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
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
            'network' => ['type' => 'string', 'default' => null, 'label' => '传输协议', 'options' => ['tcp' => 'TCP', 'kcp' => 'KCP', 'ws' => 'WebSocket', 'http' => 'HTTP/2', 'quic' => 'QUIC', 'grpc' => 'gRPC', 'xhttp' => 'XHTTP']],
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
        ), '[vless]');
    }

    private function registerTrojan(): void
    {
        $this->registerProtocolDefinition('trojan', 'Trojan', [
            'tls' => ['type' => 'integer', 'default' => 1, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => 'TLS', '2' => 'Reality']],
            'network' => ['type' => 'string', 'default' => null, 'label' => '传输协议', 'options' => ['tcp' => 'TCP', 'kcp' => 'KCP', 'ws' => 'WebSocket', 'quic' => 'QUIC', 'grpc' => 'gRPC', 'xhttp' => 'XHTTP']],
            'network_settings' => ['type' => 'array', 'default' => null, 'label' => '网络设置'],
            'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称', 'show_when' => ['tls' => '1']],
            'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接', 'show_when' => ['tls' => '1']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
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
        ), '[trojan]');
    }

    private function registerHysteria(): void
    {
        $this->registerProtocolDefinition('hysteria', 'Hysteria', [
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
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
            'hop_interval' => ['type' => 'integer', 'default' => null, 'label' => '跳跃间隔'],
        ], array_merge(
            ['version' => 'required|integer', 'alpn' => 'nullable|string',
             'obfs.open' => 'nullable|boolean', 'obfs.type' => 'string|nullable', 'obfs.password' => 'string|nullable',
             'bandwidth.up' => 'nullable|integer', 'bandwidth.down' => 'nullable|integer', 'hop_interval' => 'integer|nullable'],
            self::getTlsObjectValidationRules(),
        ), [1 => '[Hy]', 2 => '[Hy2]']);
    }

    private function registerTUIC(): void
    {
        $this->registerProtocolDefinition('tuic', 'TUIC', [
            'version' => ['type' => 'integer', 'default' => 5, 'label' => '版本', 'options' => ['5' => 'v5', '4' => 'v4', '3' => 'v3']],
            'congestion_control' => ['type' => 'string', 'default' => 'cubic', 'label' => '拥塞控制', 'options' => ['cubic' => 'CUBIC', 'bbr' => 'BBR', 'new_reno' => 'New Reno']],
            'alpn' => ['type' => 'array', 'default' => ['h3'], 'label' => 'ALPN', 'options' => ['h3' => 'h3 (HTTP/3)', 'h2' => 'h2 (HTTP/2)', 'http/1.1' => 'http/1.1', 'spdy/3' => 'spdy/3', 'h1' => 'h1']],
            'udp_relay_mode' => ['type' => 'string', 'default' => 'native', 'label' => 'UDP中继模式', 'options' => ['native' => 'Native', 'quic' => 'QUIC']],
            'tls' => ['type' => 'object', 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], [
            'version' => 'nullable|integer|in:3,4,5',
            'congestion_control' => 'nullable|string|in:cubic,bbr,new_reno',
            'alpn' => 'nullable|array',
            'udp_relay_mode' => 'nullable|string|in:native,quic',
        ], '[tuic]');
    }

    private function registerAnyTLS(): void
    {
        $this->registerProtocolDefinition('anytls', 'AnyTLS', [
            'padding_scheme' => ['type' => 'array', 'default' => [
                "stop=8", "0=30-30", "1=100-400",
                "2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000",
                "3=9-9,500-1000", "4=500-1000", "5=500-1000",
                "6=500-1000", "7=500-1000"
            ], 'label' => '填充方案'],
            'tls' => ['type' => 'object', 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], array_merge(
            ['padding_scheme' => 'nullable|array'],
            self::getTlsObjectValidationRules(true),
        ), '[anytls]');
    }

    private function registerSOCKS(): void
    {
        $this->registerProtocolDefinition('socks', 'SOCKS', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], ['tls' => 'nullable|integer'], '[socks]');
    }

    private function registerNaive(): void
    {
        $this->registerProtocolDefinition('naive', 'NaïveProxy', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], ['tls' => 'required|integer'], '[naive]');
    }

    private function registerHTTP(): void
    {
        $this->registerProtocolDefinition('http', 'HTTP', [
            'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS', 'options' => ['0' => '关闭', '1' => '开启']],
            'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全连接'],
                'ech' => ['type' => 'object', 'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'ECH'],
                    'config' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置', 'show_when' => ['enabled' => 'true']],
                    'query_server_name' => ['type' => 'string', 'default' => null, 'label' => 'ECH查询域名', 'show_when' => ['enabled' => 'true']],
                    'key' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥', 'show_when' => ['enabled' => 'true']],
                    'key_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH密钥路径', 'show_when' => ['enabled' => 'true']],
                    'config_path' => ['type' => 'string', 'default' => null, 'label' => 'ECH配置路径', 'show_when' => ['enabled' => 'true']],
                ], 'label' => 'ECH配置'],
            ], 'label' => 'TLS设置'],
        ], ['tls' => 'required|integer'], '[http]');
    }

    private function registerMieru(): void
    {
        $this->registerProtocolDefinition('mieru', 'Mieru', [
            'transport' => ['type' => 'string', 'default' => 'TCP', 'label' => '传输方式', 'options' => ['TCP' => 'TCP', 'UDP' => 'UDP']],
            'traffic_pattern' => ['type' => 'string', 'default' => '', 'label' => '流量模式'],
            ...self::getMultiplexFields(),
        ], array_merge(
            ['transport' => 'required|string|in:TCP,UDP', 'traffic_pattern' => 'string'],
            self::getMultiplexValidationRules(),
        ), '[mieru]');
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
        return [
            'utls' => ['type' => 'object', 'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false, 'label' => '启用uTLS'],
                'fingerprint' => ['type' => 'string', 'default' => 'chrome', 'label' => '指纹', 'options' => ['chrome' => 'Chrome', 'firefox' => 'Firefox', 'safari' => 'Safari', 'ios' => 'iOS', 'android' => 'Android', 'edge' => 'Edge', 'qq' => 'QQ', 'random' => 'Random', 'randomized' => 'Randomized'], 'show_when' => ['enabled' => 'true']],
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
        return [
            'utls.enabled' => 'nullable|boolean',
            'utls.fingerprint' => 'nullable|string|in:chrome,firefox,safari,ios,android,edge,qq,random,randomized',
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
