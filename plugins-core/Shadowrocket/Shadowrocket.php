<?php

namespace Plugin\Shadowrocket;

use App\Utils\Helper;
use App\Support\AbstractProtocol;
use Plugin\CoreProtocols\ProtocolTypes;

class Shadowrocket extends AbstractProtocol
{
    public $flags = ['shadowrocket'];
    public $allowedProtocols = [
        ProtocolTypes::SHADOWSOCKS,
        ProtocolTypes::VMESS,
        ProtocolTypes::VLESS,
        ProtocolTypes::TROJAN,
        ProtocolTypes::HYSTERIA,
        ProtocolTypes::TUIC,
        ProtocolTypes::ANYTLS,
        ProtocolTypes::SOCKS,
        ProtocolTypes::MIERU,
        ProtocolTypes::SUDOKU,
    ];

    protected $protocolRequirements = [
        'shadowrocket.hysteria.protocol_settings.version' => [2 => '1993'],
        'shadowrocket.anytls.base_version' => '2592',
        'shadowrocket.trojan.protocol_settings.network' => [
            'whitelist' => ['tcp', 'ws', 'grpc', 'h2', 'httpupgrade', 'xhttp'],
            'strict' => true,
        ],
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = admin_setting('app_name', 'Fboard');

        $uri = '';
        //display remaining traffic and expire date
        $upload = round($user['u'] / (1024 * 1024 * 1024), 2);
        $download = round($user['d'] / (1024 * 1024 * 1024), 2);
        $totalTraffic = round($user['transfer_enable'] / (1024 * 1024 * 1024), 2);
        $expiredDate = $user['expired_at'] === null ? 'N/A' : date('Y-m-d', $user['expired_at']);
        $uri .= "REMARKS=$appName\r\n";
        $uri .= "STATUS=🚀↑:{$upload}GB,↓:{$download}GB,TOT:{$totalTraffic}GB💡Expires:{$expiredDate}\r\n";
        foreach ($servers as $item) {
            if ($item['type'] === ProtocolTypes::SHADOWSOCKS) {
                $uri .= self::buildShadowsocks($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::VMESS) {
                $uri .= self::buildVmess($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::VLESS) {
                $uri .= self::buildVless($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::TROJAN) {
                $uri .= self::buildTrojan($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::HYSTERIA) {
                $uri .= self::buildHysteria($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::TUIC) {
                $uri .= self::buildTuic($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::ANYTLS) {
                $uri .= self::buildAnyTLS($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::SOCKS) {
                $uri .= self::buildSocks($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::MIERU) {
                $uri .= self::buildMieru($item['password'], $item);
            }
            if ($item['type'] === ProtocolTypes::SUDOKU) {
                $uri .= self::buildSudoku($item['password'], $item);
            }
        }
        return response(base64_encode($uri))
            ->header('content-type', 'text/plain');
    }


    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $password = data_get($server, 'password', $password);
        $str = str_replace(
            ['+', '/', '='],
            ['-', '_', ''],
            base64_encode(data_get($protocol_settings, 'cipher') . ":{$password}")
        );
        $addr = Helper::wrapIPv6($server['host']);

        $uri = "ss://{$str}@{$addr}:{$server['port']}";
        $plugin = data_get($protocol_settings, 'plugin') == 'obfs' ? 'obfs-local' : data_get($protocol_settings, 'plugin');
        $plugin_opts = data_get($protocol_settings, 'plugin_opts');
        if ($plugin && $plugin_opts) {
            $uri .= '/?' . 'plugin=' . $plugin . ';' . rawurlencode($plugin_opts);
        }
        return $uri . "#{$name}\r\n";
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $userinfo = base64_encode('auto:' . $uuid . '@' . Helper::wrapIPv6($server['host']) . ':' . $server['port']);
        $config = [
            'tfo' => 1,
            'remark' => $server['name'],
            'alterId' => 0
        ];
        if (data_get($protocol_settings, 'tls')) {
            $config['tls'] = 1;
            if (data_get($protocol_settings, 'tls_settings')) {
                if (!!data_get($protocol_settings, 'tls_settings.allow_insecure'))
                    $config['allowInsecure'] = (int) data_get($protocol_settings, 'tls_settings.allow_insecure');
                if (!!data_get($protocol_settings, 'tls_settings.server_name'))
                    $config['peer'] = data_get($protocol_settings, 'tls_settings.server_name');
            }
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                if (data_get($protocol_settings, 'network_settings.header.type', 'none') !== 'none') {
                    $config['obfs'] = data_get($protocol_settings, 'network_settings.header.type');
                    $config['path'] = \Illuminate\Support\Arr::random(data_get($protocol_settings, 'network_settings.header.request.path', ['/']));
                    $config['obfsParam'] = \Illuminate\Support\Arr::random(data_get($protocol_settings, 'network_settings.header.request.headers.Host', ['www.example.com']));
                }
                break;
            case 'ws':
                $config['obfs'] = "websocket";
                $config['path'] = data_get($protocol_settings, 'network_settings.path');
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host')) {
                    $config['obfsParam'] = $host;
                }
                break;
            case 'grpc':
                $config['obfs'] = "grpc";
                $config['path'] = data_get($protocol_settings, 'network_settings.serviceName');
                $config['host'] = data_get($protocol_settings, 'tls_settings.server_name') ?? $server['host'];
                break;
            case 'httpupgrade':
                $config['obfs'] = "httpupgrade";
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host'])) {
                    $config['obfsParam'] = $host;
                }
            break;
            case 'h2':
                $config['obfs'] = "h2";
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($host = data_get($protocol_settings, 'network_settings.host')) {
                    $config['obfsParam'] = $host[0] ?? $server['host'];
                    $config['peer'] = $host [0] ?? $server['host'];
                }
                break;
            case 'xhttp':
                $config['obfs'] = "xhttp";
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host'])) {
                    $config['obfsParam'] = $host;
                }
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto')) {
                    $config['mode'] = $mode;
                }
                break;
        }
        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vmess://{$userinfo}?{$query}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVless($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $host = $server['host'];
        $port = $server['port'];
        $name = $server['name'];

        // 标准 VLESS URI：vless://<uuid>@<host>:<port>?...#<name>
        // Shadowrocket 仅在标准格式下正确识别 VLESS Encryption（客户端公钥）；
        // 旧式 base64(userinfo)+obfs/peer/xtls 格式无法可靠下发 vlessenc。
        $config = [
            'tfo' => 1,
            'security' => '',
            'encryption' => match (data_get($protocol_settings, 'encryption.enabled')) {
                true => data_get($protocol_settings, 'encryption.encryption', 'none') ?: 'none',
                default => 'none',
            },
            'type' => data_get($protocol_settings, 'network'),
            'flow' => data_get($protocol_settings, 'flow'),
        ];

        $tlsMode = (int) data_get($protocol_settings, 'tls', 0);
        switch ($tlsMode) {
            case 1:
                $config['security'] = 'tls';
                if (data_get($protocol_settings, 'tls_settings.allow_insecure')) {
                    $config['allowInsecure'] = '1';
                }
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $config['sni'] = $serverName;
                }
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $config['fp'] = $fp;
                }
                if ($alpn = data_get($protocol_settings, 'tls_settings.client_alpn', data_get($protocol_settings, 'tls_settings.alpn'))) {
                    $config['alpn'] = is_array($alpn) ? implode(',', $alpn) : $alpn;
                }
                if ($ech = Helper::normalizeEchSettings(data_get($protocol_settings, 'tls_settings.ech'))) {
                    if ($echConfigList = Helper::toMihomoEchConfig(data_get($ech, 'config'))) {
                        $config['ech'] = $echConfigList;
                    }
                    if ($echSni = data_get($ech, 'query_server_name')) {
                        $config['echSni'] = $echSni;
                    }
                }
                break;
            case 2:
                $config['security'] = 'reality';
                $config['pbk'] = data_get($protocol_settings, 'reality_settings.public_key');
                $config['sid'] = data_get($protocol_settings, 'reality_settings.short_id');
                $config['sni'] = data_get($protocol_settings, 'reality_settings.server_name');
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $config['fp'] = $fp;
                }
                break;
            default:
                break;
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                // 标准 VLESS URI 用 headerType + path + host 表示 TCP(RAW) 的 HTTP 伪装头
                if (data_get($protocol_settings, 'network_settings.header.type', 'none') !== 'none') {
                    $config['headerType'] = data_get($protocol_settings, 'network_settings.header.type');
                    $config['path'] = \Illuminate\Support\Arr::random(data_get($protocol_settings, 'network_settings.header.request.path', ['/']));
                    $config['host'] = \Illuminate\Support\Arr::random(data_get($protocol_settings, 'network_settings.header.request.headers.Host', ['www.example.com']));
                }
                break;
            case 'ws':
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($wsHost = data_get($protocol_settings, 'network_settings.headers.Host')) {
                    $config['host'] = $wsHost;
                }
                break;
            case 'grpc':
                if ($serviceName = data_get($protocol_settings, 'network_settings.serviceName')) {
                    $config['serviceName'] = $serviceName;
                }
                break;
            case 'h2':
                $config['type'] = 'http';
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($h2Host = data_get($protocol_settings, 'network_settings.host')) {
                    $config['host'] = is_array($h2Host) ? implode(',', $h2Host) : $h2Host;
                }
                break;
            case 'kcp':
                $config['headerType'] = data_get($protocol_settings, 'network_settings.header.type', 'none');
                if ($seed = data_get($protocol_settings, 'network_settings.seed')) {
                    $config['seed'] = $seed;
                }
                break;
            case 'httpupgrade':
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                $config['host'] = data_get($protocol_settings, 'network_settings.host', $host);
                break;
            case 'xhttp':
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                $config['host'] = data_get($protocol_settings, 'network_settings.host', $host);
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto')) {
                    $config['mode'] = $mode;
                }
                $extra = data_get($protocol_settings, 'network_settings.extra');
                if (!is_array($extra)) {
                    $extra = [];
                }
                $downloadSettings = data_get($protocol_settings, 'network_settings.downloadSettings');
                if (is_array($downloadSettings) && !empty($downloadSettings)) {
                    $extra['downloadSettings'] = $downloadSettings;
                }
                if (!empty($extra)) {
                    $config['extra'] = json_encode($extra);
                }
                break;
            default:
                break;
        }

        $config = array_filter($config, static fn ($value) => $value !== null && $value !== '');

        $user = $uuid . '@' . Helper::wrapIPv6($host) . ':' . $port;
        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $fragment = rawurlencode($name);

        return sprintf("vless://%s?%s#%s\r\n", $user, $query, $fragment);
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $params = [];
        $tlsMode = (int) data_get($protocol_settings, 'tls', 1);

        switch ($tlsMode) {
            case 2: // Reality
                $params['security'] = 'reality';
                $params['pbk'] = data_get($protocol_settings, 'reality_settings.public_key');
                $params['sid'] = data_get($protocol_settings, 'reality_settings.short_id');
                $params['sni'] = data_get($protocol_settings, 'reality_settings.server_name');
                break;
            default: // Standard TLS
                $params['allowInsecure'] = (int) data_get($protocol_settings, 'tls_settings.allow_insecure');
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $params['peer'] = $serverName;
                }
                break;
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'grpc':
                $params['obfs'] = 'grpc';
                $params['path'] = data_get($protocol_settings, 'network_settings.serviceName');
                break;
            case 'ws':
                $host = data_get($protocol_settings, 'network_settings.headers.Host');
                $path = data_get($protocol_settings, 'network_settings.path');
                $params['plugin'] = "obfs-local;obfs=websocket;obfs-host={$host};obfs-uri={$path}";
                break;
            case 'h2':
                $params['obfs'] = 'h2';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $params['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host']))
                    $params['obfsParam'] = is_array($host) ? $host[0] : $host;
                break;
            case 'httpupgrade':
                $params['obfs'] = 'httpupgrade';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $params['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host']))
                    $params['obfsParam'] = $host;
                break;
            case 'xhttp':
                $params['obfs'] = 'xhttp';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $params['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host']))
                    $params['obfsParam'] = $host;
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto'))
                    $params['mode'] = $mode;
                break;
        }
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);

        $uri = "trojan://{$password}@{$addr}:{$server['port']}?{$query}&tfo=1#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildHysteria($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $uri = ''; // 初始化变量

        switch (data_get($protocol_settings, 'version')) {
            case 1:
                $params = [
                    "auth" => $password,
                    "upmbps" => data_get($protocol_settings, 'bandwidth.up'),
                    "downmbps" => data_get($protocol_settings, 'bandwidth.down'),
                    "protocol" => 'udp',
                    "fastopen" => 1,
                ];
                if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
                    $params['peer'] = $serverName;
                }
                if (data_get($protocol_settings, 'obfs.open')) {
                    $params["obfs"] = "xplus";
                    $params["obfsParam"] = data_get($protocol_settings, 'obfs.password');
                }
                $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure');
                if (isset($server['ports']))
                    $params['mport'] = $server['ports'];
                $query = http_build_query($params);
                $addr = Helper::wrapIPv6($server['host']);

                $uri = "hysteria://{$addr}:{$server['port']}?{$query}#{$server['name']}";
                $uri .= "\r\n";
                break;
            case 2:
                $params = [
                    "obfs" => 'none',
                    "fastopen" => 1
                ];
                if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
                    $params['peer'] = $serverName;
                }
                if (data_get($protocol_settings, 'obfs.open')) {
                    $params['obfs'] = data_get($protocol_settings, 'obfs.type');
                    $params['obfs-password'] = data_get($protocol_settings, 'obfs.password');
                }
                $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure');
                if (isset($protocol_settings['hop_interval'])) {
                    $params['keepalive'] = data_get($protocol_settings, 'hop_interval');
                }
                if (isset($server['ports'])) {
                    $params['mport'] = $server['ports'];
                }
                $query = http_build_query($params);
                $addr = Helper::wrapIPv6($server['host']);

                $uri = "hysteria2://{$password}@{$addr}:{$server['port']}?{$query}#{$server['name']}";
                $uri .= "\r\n";
                break;
        }
        return $uri;
    }
    public static function buildTuic($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $params = [
            'alpn' => data_get($protocol_settings, 'alpn'),
            'sni' => data_get($protocol_settings, 'tls.server_name'),
            'insecure' => data_get($protocol_settings, 'tls.allow_insecure'),
            'congestion_control' => data_get($protocol_settings, 'congestion_control', 'cubic')
        ];
        if (data_get($protocol_settings, 'version') === 4) {
            $params['token'] = $password;
        } else {
            $params['uuid'] = $password;
            $params['password'] = $password;
        }
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);
        $uri = "tuic://{$addr}:{$server['port']}?{$query}#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildAnyTLS($password, $server)
    {
        // 对齐 anytls 官方 URI 与 General 订阅格式，保证 Shadowrocket / v2rayN 等可解析
        $protocol_settings = data_get($server, 'protocol_settings', []) ?: [];
        $name = rawurlencode((string) ($server['name'] ?? ''));
        $params = [];
        if ($sni = data_get($protocol_settings, 'tls.server_name')) {
            $params['sni'] = $sni;
        }
        $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure') ? '1' : '0';
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);
        $userinfo = rawurlencode((string) $password);
        $uri = "anytls://{$userinfo}@{$addr}:{$server['port']}/?{$query}#{$name}\r\n";
        return $uri;
    }

    public static function buildSocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $addr = Helper::wrapIPv6($server['host']);
        $uri = 'socks://' . base64_encode("{$password}:{$password}@{$addr}:{$server['port']}") . "?method=auto#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildMieru($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $addr = Helper::wrapIPv6($server['host']);
        $params = [
            'udp' => 1,
            'transport' => strtolower(data_get($protocol_settings, 'transport', 'TCP'))
        ];

        if (isset($server['ports'])) {
            $params['mport'] = $server['ports'];
        }

        if ($multiplexing = data_get($protocol_settings, 'multiplexing')) {
            $params['multiplexing'] = $multiplexing;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $username = rawurlencode($password);
        $password = rawurlencode($password);
        $uri = "mierus://{$username}:{$password}@{$addr}:{$server['port']}?{$query}#{$name}";
        $uri .= "\r\n";
        return $uri;
    }
    public static function buildSudoku($password, $server)
    {
        // Shadowrocket 2.2.92+ 原生 Sudoku 导出方言（非官方 sudoku://base64url(json) 短链）：
        //   sudoku://<base64("<aead>:<key>@<host>:<port>")>?obfsParam=<json>&obfs=httpmask&udp=1#<name>
        // obfsParam 内字段命名与 mihomo Sudoku 代理一致（padding-min/table-type/httpmask.*），
        // 与 Shadowrocket 实际导出的链接逐字段对齐；官方短链格式 Shadowrocket 无法直接导入。
        $protocol_settings = $server['protocol_settings'] ?? [];
        $name = $server['name'] ?? '';
        $host = $server['host'];
        $port = (int) $server['port'];

        $aead = data_get($protocol_settings, 'aead_method', 'chacha20-poly1305') ?: 'chacha20-poly1305';
        $userinfo = rtrim(base64_encode($aead . ':' . $password . '@' . Helper::wrapIPv6($host) . ':' . $port), '=');

        $httpmaskHost = data_get($protocol_settings, 'httpmask.host') ?: $host;
        $tls = data_get($protocol_settings, 'httpmask.tls');
        $httpmask = [
            'tls' => $tls === null ? true : (bool) $tls,
            'host' => $httpmaskHost,
            // Shadowrocket 导出为整数 0/1（非 bool），其导入解析器按此形式读取
            'disable' => (int) (bool) data_get($protocol_settings, 'httpmask.disable', false),
            'mode' => data_get($protocol_settings, 'httpmask.mode', 'legacy') ?: 'legacy',
        ];
        $pathRoot = \App\Support\SudokuKey::normalizeHttpMaskPathRoot(
            data_get($protocol_settings, 'httpmask.path_root')
        );
        if ($pathRoot !== null) {
            // Shadowrocket 导出带前导斜杠，如 "/QL0EUOjp"
            $httpmask['path-root'] = '/' . $pathRoot;
        }

        // 未开启高级模式时不下发高级参数，由客户端默认值接管
        $advanced = (bool) data_get($protocol_settings, 'advanced', false);
        $obfsParam = [
            'httpmask' => $httpmask,
        ];
        if ($advanced) {
            $obfsParam['padding-min'] = (int) data_get($protocol_settings, 'padding_min', 5);
            $obfsParam['table-type'] = data_get($protocol_settings, 'table_type', 'prefer_entropy') ?: 'prefer_entropy';
            $obfsParam['enable-pure-downlink'] = (bool) data_get($protocol_settings, 'enable_pure_downlink', true);
            $obfsParam['padding-max'] = (int) data_get($protocol_settings, 'padding_max', 15);
            if ($custom = data_get($protocol_settings, 'custom_table')) {
                $obfsParam['custom-table'] = $custom;
            }
            if ($customs = data_get($protocol_settings, 'custom_tables')) {
                if (is_array($customs) && count($customs) > 0) {
                    $obfsParam['custom-tables'] = array_values($customs);
                }
            }
        }
        $mux = data_get($protocol_settings, 'multiplex');
        if (is_array($mux)) {
            $mux = !empty($mux['enabled']) ? 'on' : 'off';
        }
        if ($mux && $mux !== 'off') {
            $obfsParam['multiplex'] = $mux;
        }

        $params = [
            'obfsParam' => json_encode($obfsParam),
            'obfs' => 'httpmask',
            'udp' => 1,
        ];
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $uri = "sudoku://{$userinfo}?{$query}";
        if ($name !== '' && $name !== null) {
            $uri .= '#' . rawurlencode((string) $name);
        }
        return $uri . "\r\n";
    }

}
