<?php

namespace Plugin\General;

use App\Utils\Helper;
use Illuminate\Support\Arr;
use App\Support\AbstractProtocol;
use Plugin\CoreProtocols\ProtocolTypes;

class General extends AbstractProtocol
{
    public $flags = ['general', 'v2rayn', 'v2rayng', 'passwall', 'ssrplus', 'sagernet', 'inhive'];

    public $allowedProtocols = [
        ProtocolTypes::VMESS,
        ProtocolTypes::VLESS,
        ProtocolTypes::SHADOWSOCKS,
        ProtocolTypes::TROJAN,
        ProtocolTypes::HYSTERIA,
        ProtocolTypes::ANYTLS,
        ProtocolTypes::SOCKS,
        ProtocolTypes::TUIC,
        ProtocolTypes::HTTP,
        ProtocolTypes::SHADOWQUIC,
        ProtocolTypes::TRUSTTUNNEL,
        ProtocolTypes::MASQUE,
    ];

    protected $protocolRequirements = [
        'v2rayng.hysteria.protocol_settings.version' => [2 => '1.9.5'],
        'v2rayn.hysteria.protocol_settings.version' => [2 => '6.31'],
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $uri = '';

        foreach ($servers as $item) {
            $uri .= match ($item['type']) {
                ProtocolTypes::VMESS => self::buildVmess($item['password'], $item),
                ProtocolTypes::VLESS => self::buildVless($item['password'], $item),
                ProtocolTypes::SHADOWSOCKS => self::buildShadowsocks($item['password'], $item),
                ProtocolTypes::TROJAN => self::buildTrojan($item['password'], $item),
                ProtocolTypes::HYSTERIA => self::buildHysteria($item['password'], $item),
                ProtocolTypes::ANYTLS => self::buildAnyTLS($item['password'], $item),
                ProtocolTypes::SOCKS => self::buildSocks($item['password'], $item),
                ProtocolTypes::TUIC => self::buildTuic($item['password'], $item),
                ProtocolTypes::HTTP => self::buildHttp($item['password'], $item),
                ProtocolTypes::SHADOWQUIC => self::buildShadowQUIC($item['password'], $item),
                ProtocolTypes::TRUSTTUNNEL => self::buildTrustTunnel($item['password'], $item),
                ProtocolTypes::MASQUE => self::buildMasque($item['password'], $item),
                default => '',
            };
        }
        return response(base64_encode($uri))
            ->header('content-type', 'text/plain')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
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
        $plugin = data_get($protocol_settings, 'plugin');
        $plugin_opts = data_get($protocol_settings, 'plugin_opts');
        $url = "ss://{$str}@{$addr}:{$server['port']}";
        if ($plugin && $plugin_opts) {
            $url .= '/?' . 'plugin=' . rawurlencode($plugin . ';' . $plugin_opts);
        }
        $url .= "#{$name}\r\n";
        return $url;
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $config = [
            "v" => "2",
            "ps" => $server['name'],
            "add" => $server['host'],
            "port" => (string) $server['port'],
            "id" => $uuid,
            "aid" => '0',
            "net" => data_get($server, 'protocol_settings.network'),
            "type" => "none",
            "host" => "",
            "path" => "",
            "tls" => data_get($protocol_settings, 'tls') ? "tls" : "",
        ];
        if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
            $config['sni'] = $serverName;
        }
        if ($alpn = data_get($protocol_settings, 'tls_settings.client_alpn', data_get($protocol_settings, 'tls_settings.alpn'))) {
            $config['alpn'] = is_array($alpn) ? implode(',', $alpn) : $alpn;
        }
        if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
            $config['fp'] = $fp;
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                if (data_get($protocol_settings, 'network_settings.header.type', 'none') !== 'none') {
                    $config['type'] = data_get($protocol_settings, 'network_settings.header.type', 'http');
                    $config['path'] = Arr::random(data_get($protocol_settings, 'network_settings.header.request.path', ['/']));
                    $config['host'] =
                        data_get($protocol_settings, 'network_settings.header.request.headers.Host')
                        ? Arr::random(data_get($protocol_settings, 'network_settings.header.request.headers.Host', ['/']), )
                        : null;
                }
                break;
            case 'ws':
                $config['type'] = 'ws';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host'))
                    $config['host'] = $host;
                break;
            case 'grpc':
                $config['type'] = 'grpc';
                if ($path = data_get($protocol_settings, 'network_settings.serviceName'))
                    $config['path'] = $path;
                break;
            case 'h2':
                $config['net'] = 'h2';
                $config['type'] = 'h2';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $config['host'] = is_array($host) ? implode(',', $host) : $host;
                break;
            case 'httpupgrade':
                $config['net'] = 'httpupgrade';
                $config['type'] = 'httpupgrade';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                $config['host'] = data_get($protocol_settings, 'network_settings.host', $server['host']);
                break;
            case 'xhttp':
                $config['net'] = 'xhttp';
                $config['type'] = 'xhttp';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                $config['host'] = data_get($protocol_settings, 'network_settings.host', $server['host']);
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto'))
                    $config['mode'] = $mode;
                $extra = data_get($protocol_settings, 'network_settings.extra');
                if (!is_array($extra)) $extra = [];
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
        return "vmess://" . base64_encode(json_encode($config)) . "\r\n";
    }

    public static function buildVless($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $host = $server['host'];
        $port = $server['port'];
        $name = $server['name'];

        $config = [
            'mode' => 'multi',
            'security' => '',
            'encryption' => match (data_get($protocol_settings, 'encryption.enabled')) {
                true => data_get($protocol_settings, 'encryption.encryption', 'none'),
                default => 'none'
            },
            'type' => data_get($server, 'protocol_settings.network'),
            'flow' => data_get($protocol_settings, 'flow'),
        ];
        switch (data_get($server, 'protocol_settings.tls')) {
            case 1:
                $config['security'] = "tls";
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $config['fp'] = $fp;
                }
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $config['sni'] = $serverName;
                }
                if (data_get($protocol_settings, 'tls_settings.allow_insecure')) {
                    $config['allowInsecure'] = '1';
                }
                if ($alpn = data_get($protocol_settings, 'tls_settings.client_alpn', data_get($protocol_settings, 'tls_settings.alpn'))) {
                    $config['alpn'] = is_array($alpn) ? implode(',', $alpn) : $alpn;
                }
                break;
            case 2:
                $config['security'] = "reality";
                $config['pbk'] = data_get($protocol_settings, 'reality_settings.public_key');
                $config['sid'] = data_get($protocol_settings, 'reality_settings.short_id');
                $config['sni'] = data_get($protocol_settings, 'reality_settings.server_name');
                $config['servername'] = data_get($protocol_settings, 'reality_settings.server_name');
                $config['spx'] = "/";
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $config['fp'] = $fp;
                }
                break;
            default:
                break;
        }
        switch (data_get($server, 'protocol_settings.network')) {
            case 'ws':
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                if ($wsHost = data_get($protocol_settings, 'network_settings.headers.Host'))
                    $config['host'] = $wsHost;
                break;
            case 'grpc':
                if ($path = data_get($protocol_settings, 'network_settings.serviceName'))
                    $config['serviceName'] = $path;
                break;
            case 'h2':
                $config['type'] = 'http';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                if ($h2Host = data_get($protocol_settings, 'network_settings.host'))
                    $config['host'] = is_array($h2Host) ? implode(',', $h2Host) : $h2Host;
                break;
            case 'kcp':
                if ($path = data_get($protocol_settings, 'network_settings.seed'))
                    $config['path'] = $path;
                $config['type'] = data_get($protocol_settings, 'network_settings.header.type', 'none');
                break;
            case 'httpupgrade':
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                $config['host'] = data_get($protocol_settings, 'network_settings.host', $server['host']);
                break;
            case 'xhttp':
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $config['path'] = $path;
                $config['host'] = data_get($protocol_settings, 'network_settings.host', $server['host']);
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto'))
                    $config['mode'] = $mode;
                $extra = data_get($protocol_settings, 'network_settings.extra');
                if (!is_array($extra)) $extra = [];
                $downloadSettings = data_get($protocol_settings, 'network_settings.downloadSettings');
                if (is_array($downloadSettings) && !empty($downloadSettings)) {
                    $extra['downloadSettings'] = $downloadSettings;
                }
                if (!empty($extra)) {
                    $config['extra'] = json_encode($extra);
                }
                break;
        }

        $user = $uuid . '@' . Helper::wrapIPv6($host) . ':' . $port;
        $query = http_build_query($config);
        $fragment = rawurlencode($name);
        $link = sprintf("vless://%s?%s#%s\r\n", $user, $query, $fragment);
        return $link;
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $array = [];
        $tlsMode = (int) data_get($protocol_settings, 'tls', 1);

        switch ($tlsMode) {
            case 2:
                $array['security'] = 'reality';
                $array['pbk'] = data_get($protocol_settings, 'reality_settings.public_key');
                $array['sid'] = data_get($protocol_settings, 'reality_settings.short_id');
                $array['sni'] = data_get($protocol_settings, 'reality_settings.server_name');
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $array['fp'] = $fp;
                }
                break;
            default:
                $array['allowInsecure'] = (bool) data_get($protocol_settings, 'tls_settings.allow_insecure', false);
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $array['peer'] = $serverName;
                    $array['sni'] = $serverName;
                }
                if ($alpn = data_get($protocol_settings, 'tls_settings.client_alpn', data_get($protocol_settings, 'tls_settings.alpn'))) {
                    $array['alpn'] = is_array($alpn) ? implode(',', $alpn) : $alpn;
                }
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $array['fp'] = $fp;
                }
                break;
        }

        switch (data_get($server, 'protocol_settings.network')) {
            case 'ws':
                $array['type'] = 'ws';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host'))
                    $array['host'] = $host;
                break;
            case 'grpc':
                $array['type'] = 'grpc';
                if ($serviceName = data_get($protocol_settings, 'network_settings.serviceName'))
                    $array['serviceName'] = $serviceName;
                break;
            case 'h2':
                $array['type'] = 'http';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host'))
                    $array['host'] = is_array($host) ? implode(',', $host) : $host;
                break;
            case 'httpupgrade':
                $array['type'] = 'httpupgrade';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['path'] = $path;
                $array['host'] = data_get($protocol_settings, 'network_settings.host', $server['host']);
                break;
            case 'xhttp':
                $array['type'] = 'xhttp';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $array['path'] = $path;
                $array['host'] = data_get($protocol_settings, 'network_settings.host', $server['host']);
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto'))
                    $array['mode'] = $mode;
                $extra = data_get($protocol_settings, 'network_settings.extra');
                if (!is_array($extra)) $extra = [];
                $downloadSettings = data_get($protocol_settings, 'network_settings.downloadSettings');
                if (is_array($downloadSettings) && !empty($downloadSettings)) {
                    $extra['downloadSettings'] = $downloadSettings;
                }
                if (!empty($extra)) {
                    $array['extra'] = json_encode($extra);
                }
                break;
            default:
                break;
        }
        $query = http_build_query($array);
        $addr = Helper::wrapIPv6($server['host']);

        $uri = "trojan://{$password}@{$addr}:{$server['port']}?{$query}#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildHysteria($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $params = [];
        $version = data_get($protocol_settings, 'version', 2);

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $params['sni'] = $serverName;
        }
        $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure') ? '1' : '0';

        $name = rawurlencode($server['name']);
        $addr = Helper::wrapIPv6($server['host']);

        if ($version === 2) {
            if (data_get($protocol_settings, 'obfs.open')) {
                $params['obfs'] = 'salamander';
                $params['obfs-password'] = data_get($protocol_settings, 'obfs.password');
            }
            if (isset($server['ports'])) {
                $params['mport'] = $server['ports'];
            }

            $query = http_build_query($params);
            $uri = "hysteria2://{$password}@{$addr}:{$server['port']}?{$query}#{$name}";
        } else {
            $params['protocol'] = 'udp';
            $params['auth'] = $password;
            if ($upMbps = data_get($protocol_settings, 'bandwidth.up'))
                $params['upmbps'] = $upMbps;
            if ($downMbps = data_get($protocol_settings, 'bandwidth.down'))
                $params['downmbps'] = $downMbps;
            if (data_get($protocol_settings, 'obfs.open') && ($obfsPassword = data_get($protocol_settings, 'obfs.password'))) {
                $params['obfs'] = 'xplus';
                $params['obfsParam'] = $obfsPassword;
            }

            $query = http_build_query($params);
            $uri = "hysteria://{$addr}:{$server['port']}?{$query}#{$name}";
        }
        $uri .= "\r\n";

        return $uri;
    }


    public static function buildTuic($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $name = rawurlencode($server['name']);
        $addr = Helper::wrapIPv6($server['host']);
        $port = $server['port'];
        $uuid = $password;
        $pass = $password;

        $queryParams = [];

        if ($sni = data_get($protocol_settings, 'tls.server_name')) {
            $queryParams['sni'] = $sni;
        }

        if ($alpn = data_get($protocol_settings, 'alpn')) {
            if (is_array($alpn)) {
                $queryParams['alpn'] = implode(',', $alpn);
            } else {
                $queryParams['alpn'] = $alpn;
            }
        }

        $congestion = data_get($protocol_settings, 'congestion_control', 'cubic');
        $queryParams['congestion_control'] = $congestion;

        $udpRelay = data_get($protocol_settings, 'udp_relay_mode', 'native');
        $queryParams['udp-relay-mode'] = $udpRelay;

        if (data_get($protocol_settings, 'tls.allow_insecure')) {
            $queryParams['insecure'] = '1';
        }

        $query = http_build_query($queryParams);

        $uri = "tuic://{$uuid}:{$pass}@{$addr}:{$port}";

        if (!empty($query)) {
            $uri .= "?{$query}";
        }

        $uri .= "#{$name}\r\n";

        return $uri;
    }





    public static function buildAnyTLS($password, $server)
    {
        // 对齐 anytls 官方 URI：anytls://[auth@]host[:port]/?[key=value]...
        // 见 https://github.com/anytls/anytls-go/blob/main/docs/uri_scheme.md
        // v2rayN 的 AnytlsFmt 依赖可解析的 UserInfo + sni/insecure 查询参数。
        $protocol_settings = data_get($server, 'protocol_settings', []) ?: [];
        $name = rawurlencode((string) ($server['name'] ?? ''));
        $params = [];
        if ($sni = data_get($protocol_settings, 'tls.server_name')) {
            $params['sni'] = $sni;
        }
        // insecure 必须为 "0"/"1"，避免 bool/null 经 http_build_query 变成空值导致客户端丢弃节点
        $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure') ? '1' : '0';
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);
        // auth 含特殊字符时需百分号编码；v2rayN Resolve 侧会 UrlDecode
        $userinfo = rawurlencode((string) $password);
        $uri = "anytls://{$userinfo}@{$addr}:{$server['port']}/?{$query}#{$name}\r\n";
        return $uri;
    }

    public static function buildSocks($password, $server)
    {
        $name = rawurlencode($server['name']);
        $credentials = base64_encode("{$password}:{$password}");
        $addr = Helper::wrapIPv6($server['host']);
        return "socks://{$credentials}@{$addr}:{$server['port']}#{$name}\r\n";
    }

    public static function buildHttp($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $name = rawurlencode($server['name']);
        $addr = Helper::wrapIPv6($server['host']);
        $credentials = base64_encode("{$password}:{$password}");

        $params = [];
        if (data_get($protocol_settings, 'tls')) {
            $params['security'] = 'tls';
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $params['sni'] = $serverName;
            }
            $params['allowInsecure'] = data_get($protocol_settings, 'tls_settings.allow_insecure') ? '1' : '0';
        }

        $uri = "http://{$credentials}@{$addr}:{$server['port']}";
        if (!empty($params)) {
            $uri .= '?' . http_build_query($params);
        }
        $uri .= "#{$name}\r\n";
        return $uri;
    }

    public static function buildShadowQUIC($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []) ?: [];
        $name = rawurlencode((string) ($server['name'] ?? ''));
        $addr = Helper::wrapIPv6($server['host']);
        $port = $server['port'];
        $user = rawurlencode((string) $password);
        $pass = rawurlencode((string) $password);

        $queryParams = [];
        if ($sni = data_get($protocol_settings, 'server_name')) {
            $queryParams['sni'] = $sni;
        }
        if ($alpn = data_get($protocol_settings, 'alpn')) {
            $queryParams['alpn'] = is_array($alpn) ? implode(',', $alpn) : $alpn;
        } else {
            $queryParams['alpn'] = 'h3';
        }
        $queryParams['congestion_control'] = data_get($protocol_settings, 'congestion_control', 'bbr');
        $queryParams['zero_rtt'] = data_get($protocol_settings, 'zero_rtt', true) ? '1' : '0';
        if (data_get($protocol_settings, 'udp_over_stream')) {
            $queryParams['udp_over_stream'] = '1';
        }

        $query = http_build_query($queryParams);
        $uri = "shadowquic://{$user}:{$pass}@{$addr}:{$port}";
        if ($query !== '') {
            $uri .= "?{$query}";
        }
        $uri .= "#{$name}\r\n";
        return $uri;
    }

    /**
     * TrustTunnel deeplink（tt://?base64url(TLV)），见 DEEP_LINK.md v1。
     * 为兼容已发布的官方客户端（max supported: 1），编码 version=1；
     * 不含 subscription_url（v2 字段），其余静态参数齐全。
     */
    public static function buildTrustTunnel($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []) ?: [];
        $password = (string) $password;
        $host = (string) ($server['host'] ?? '');
        $port = (int) ($server['port'] ?? 443);
        $hostname = (string) (data_get($protocol_settings, 'hostname') ?: $host);
        if ($hostname === '') {
            return '';
        }

        $payload = '';
        $payload .= Helper::encodeTrustTunnelTlv(0x00, Helper::encodeTlsVarint(1)); // version=1
        $payload .= Helper::encodeTrustTunnelTlv(0x01, $hostname);
        $payload .= Helper::encodeTrustTunnelTlv(0x02, $host.':'.$port);
        if ($sni = data_get($protocol_settings, 'custom_sni')) {
            $payload .= Helper::encodeTrustTunnelTlv(0x03, (string) $sni);
        }
        if (!data_get($protocol_settings, 'has_ipv6', true)) {
            $payload .= Helper::encodeTrustTunnelTlv(0x04, "\x00");
        }
        $payload .= Helper::encodeTrustTunnelTlv(0x05, $password); // username=UUID
        $payload .= Helper::encodeTrustTunnelTlv(0x06, $password);
        if (data_get($protocol_settings, 'skip_verification')) {
            $payload .= Helper::encodeTrustTunnelTlv(0x07, "\x01");
        }
        $upstreamVersion = data_get($protocol_settings, 'upstream_protocol', 'http2') === 'http3' ? 2 : 1;
        $payload .= Helper::encodeTrustTunnelTlv(0x09, Helper::encodeTlsVarint($upstreamVersion));
        if (data_get($protocol_settings, 'anti_dpi')) {
            $payload .= Helper::encodeTrustTunnelTlv(0x0A, "\x01");
        }
        if ($prefix = data_get($protocol_settings, 'client_random_prefix')) {
            $payload .= Helper::encodeTrustTunnelTlv(0x0B, (string) $prefix);
        }
        $payload .= Helper::encodeTrustTunnelTlv(0x0C, (string) ($server['name'] ?? ''));
        if ($upstreams = data_get($protocol_settings, 'dns_upstreams')) {
            $list = is_array($upstreams) ? array_values(array_filter($upstreams)) : [$upstreams];
            if ($list !== []) {
                $encoded = '';
                foreach ($list as $u) {
                    $u = (string) $u;
                    $encoded .= Helper::encodeTlsVarint(strlen($u)).$u;
                }
                $payload .= Helper::encodeTrustTunnelTlv(0x0D, $encoded);
            }
        }
        if ($key = data_get($protocol_settings, 'client_random_auth_key')) {
            $payload .= Helper::encodeTrustTunnelTlv(0x0F, (string) $key);
        }

        return 'tt://?'.Helper::base64EncodeUrlSafe($payload)."\r\n";
    }

    /**
     * MASQUE 自定义分享链接（Fboard 约定，非标准协议）：
     * masque://user:pass@host:port?version=&path=&sni=&insecure=&mtu=&address=&advertise_routes=&alpn=#name
     * 官方客户端请使用 SingBox 订阅中的 masque-client endpoint JSON。
     */
    public static function buildMasque($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []) ?: [];
        $password = (string) $password;
        $host = (string) ($server['host'] ?? '');
        $port = (int) ($server['port'] ?? 443);
        if ($host === '') {
            return '';
        }
        $versions = data_get($protocol_settings, 'versions', [3]);
        if (!is_array($versions) || $versions === []) {
            $versions = [3];
        }
        $params = [
            'version' => (int) $versions[0],
            'path' => (string) data_get($protocol_settings, 'path', '/.well-known/masque/ip/'),
            'sni' => (string) (data_get($protocol_settings, 'server_name') ?: $host),
            // 服务端证书为端点密钥自签发，客户端必须跳过校验。
            'insecure' => '1',
            'mtu' => (int) data_get($protocol_settings, 'mtu', 1280),
            'alpn' => 'h3',
        ];
        $addresses = data_get($protocol_settings, 'addresses', []);
        if ($addresses !== null && $addresses !== []) {
            $params['address'] = implode(',', (array) $addresses);
        }
        if ($routes = data_get($protocol_settings, 'advertise_routes')) {
            $params['advertise_routes'] = implode(',', (array) $routes);
        }
        $query = http_build_query($params);
        $name = rawurlencode((string) ($server['name'] ?? ''));
        $userinfo = rawurlencode($password).':'.rawurlencode($password);
        $addr = Helper::wrapIPv6($host);

        return "masque://{$userinfo}@{$addr}:{$port}/?{$query}#{$name}\r\n";
    }
}
