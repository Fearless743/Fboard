<?php

namespace App\Utils;

use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Helper
{
    public static function uuidToBase64($uuid, $length)
    {
        return base64_encode(substr($uuid, 0, $length));
    }

    public static function getServerKey($timestamp, $length)
    {
        return base64_encode(substr(md5($timestamp), 0, $length));
    }

    /**
     * 生成 usque/mihomo MASQUE 用的 ECDSA P-256 设备密钥对。
     *
     * @return array{private: string, public: string} base64(DER) 格式，
     *         private 为 SEC1 DER（mihomo private-key），public 为 PKIX DER（mihomo public-key）。
     */
    public static function generateUsqueDeviceKeypair(): array
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($res === false) {
            throw new \RuntimeException('usque keygen failed: ' . (string) openssl_error_string());
        }
        if (!openssl_pkey_export($res, $privPem)) {
            throw new \RuntimeException('usque key export failed');
        }
        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || empty($details['key'])) {
            throw new \RuntimeException('usque public key read failed');
        }
        $privDer = self::pemToDerBase64($privPem);
        $pubDer = self::pemToDerBase64($details['key']);
        if ($privDer === null || $pubDer === null) {
            throw new \RuntimeException('usque DER convert failed');
        }
        // openssl_pkey_export 给出 PKCS#8；mihomo 要求 SEC1，这里直接组装。
        $privRaw = base64_decode($privDer, true);
        $scalar = $privRaw !== false ? self::pkcs8Scalar($privRaw) : null;
        $pub65 = self::pkixRawPublicKey($pubDer);
        if ($scalar === null || $pub65 === null) {
            throw new \RuntimeException('usque SEC1 assemble failed');
        }

        return ['private' => base64_encode(self::assembleSec1($scalar, $pub65)), 'public' => $pubDer];
    }

    /**
     * PEM 文本转 base64(DER)（去头尾去换行）。非 PEM 的纯 base64 输入
     * 若能严格解码则原样返回（容错管理员直接粘贴 DER base64）。
     */
    public static function pemToDerBase64(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (str_contains($input, '-----BEGIN')) {
            $lines = preg_split('/\r?\n/', $input);
            $body = '';
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '-----')) {
                    continue;
                }
                $body .= $line;
            }
        } else {
            $body = preg_replace('/\s+/', '', $input);
        }
        if ($body === '' || base64_decode($body, true) === false) {
            return null;
        }

        return $body;
    }

    /**
     * 确保私钥为 SEC1 DER (base64)，mihomo private-key 要求 SEC1。
     * 已是合格 SEC1（含 prime256v1 OID）则原样返回；若为 PKCS#8，则用
     * 公钥（PKIX DER b64）组装出完整 SEC1；都不是则返回 null。
     */
    public static function ensureSec1DerBase64(?string $privB64, ?string $pubB64): ?string
    {
        $priv = is_string($privB64) ? base64_decode($privB64, true) : false;
        if ($priv === false || $priv === '' || $priv[0] !== "\x30") {
            return null;
        }
        $oidP256 = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        if (str_contains($priv, $oidP256)) {
            return $privB64;
        }
        // 尝试按 PKCS#8 解析出 32 字节 scalar
        $scalar = self::pkcs8Scalar($priv);
        if ($scalar === null) {
            return null;
        }
        $pub65 = self::pkixRawPublicKey($pubB64);
        if ($pub65 === null) {
            return null;
        }

        return base64_encode(self::assembleSec1($scalar, $pub65));
    }

    /**
     * 从 PKCS#8 DER 提取 EC 私钥标量（32 字节）。非预期结构返回 null。
     */
    public static function pkcs8Scalar(string $der): ?string
    {
        // 顶层 SEQ 载荷中定位 "04 20" 后 32 字节（EC 私钥标量 OCTET STRING）
        $pos = 0;
        if (strlen($der) < 2 || $der[0] !== "\x30") {
            return null;
        }
        $lb = ord($der[1]);
        if ($lb < 0x80) {
            $payload = substr($der, 2, $lb);
        } elseif ($lb === 0x81) {
            if (strlen($der) < 3) {
                return null;
            }
            $payload = substr($der, 3, ord($der[2]));
        } elseif ($lb === 0x82) {
            if (strlen($der) < 4) {
                return null;
            }
            $payload = substr($der, 4, (ord($der[2]) << 8) | ord($der[3]));
        } else {
            return null;
        }
        $needle = "\x04\x20";
        $at = strpos($payload, $needle);
        if ($at === false || $at + 34 > strlen($payload)) {
            return null;
        }

        return substr($payload, $at + 2, 32);
    }

    /**
     * 从 PKIX DER 公钥提取 65 字节 uncompressed point（04||X||Y）。
     */
    public static function pkixRawPublicKey(?string $b64): ?string
    {
        $der = is_string($b64) ? base64_decode($b64, true) : false;
        if ($der === false || $der === '' || $der[0] !== "\x30") {
            return null;
        }
        // BIT STRING 段：03 <len> 00 04 <X:32> <Y:32>
        $needle = "\x03\x42\x00\x04";
        $at = strpos($der, $needle);
        if ($at === false) {
            return null;
        }
        $point = substr($der, $at + 3, 65);
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            return null;
        }

        return $point;
    }

    /**
     * 组装 SEC1 ECPrivateKey DER：
     * SEQ{ INTEGER 1, OCTET STRING scalar, [0] OID prime256v1, [1] BIT STRING pub }。
     */
    public static function assembleSec1(string $scalar32, string $pub65): string
    {
        $oid = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $inner = "\x02\x01\x01"
            ."\x04\x20".$scalar32
            ."\xA0\x0A".$oid
            ."\xA1\x44\x03\x42\x00".$pub65;

        return "\x30".chr(strlen($inner)).$inner;
    }

    /**
     * 为 usque 静态 IP 分配：userId 在隧道前缀内确定性取偏移。
     * IPv4 跳过网络地址与服务端地址（前缀本身地址）；IPv6 取低 64 位偏移。
     *
     * @param list<string> $prefixes 如 ["10.99.0.1/24", "fd00::1/64"]
     * @return array{ip: ?string, ipv6: ?string} 始终带 /32 //128 后缀，取不到返回 null
     */
    public static function usqueStaticIPs(array $prefixes, int $userId): array
    {
        $out = ['ip' => null, 'ipv6' => null];
        foreach ($prefixes as $prefix) {
            $parts = explode('/', (string) $prefix, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$addrStr, $bitsStr] = $parts;
            $bits = (int) $bitsStr;
            if (filter_var($addrStr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if ($bits < 0 || $bits > 32) {
                    continue;
                }
                $size = 2 ** (32 - $bits);
                if ($size < 4) {
                    continue; // /31//32 无可分配主机位
                }
                $mask = $bits === 0 ? 0 : ((~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF);
                $base = ((int) ip2long($addrStr) & $mask) & 0xFFFFFFFF;
                $server = (int) ip2long($addrStr) & 0xFFFFFFFF;
                // 主机偏移落在 [2, size-2]（天然跳过 .0 网络地址；
                // 若服务端地址恰为 .1 也一并跳过，其它位置的服务端地址见下）。
                // 再跳过服务端地址（至多绕一圈）。
                $hostMax = $size - 2;
                $o = 2 + (($userId - 1) % ($hostMax - 1));
                for ($tries = 0; $tries <= $hostMax; $tries++) {
                    $candidate = ($base + $o) & 0xFFFFFFFF;
                    if ($candidate !== $server) {
                        break;
                    }
                    $o++;
                    if ($o > $hostMax) {
                        $o = 2;
                    }
                }
                $out['ip'] = long2ip($candidate).'/32';
            } elseif (filter_var($addrStr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                if ($bits < 0 || $bits > 128) {
                    continue;
                }
                $baseBin = inet_pton($addrStr);
                if ($baseBin === false || strlen($baseBin) !== 16) {
                    continue;
                }
                // 低 64 位偏移（userId 量级下唯一；前缀小于 /64 时仍落在前缀内需校验）
                $off = (($userId - 1) % 9007199254740991) + 2;
                $candidate = self::addIntToIP16($baseBin, $off);
                // 偏移不得越出前缀（比较网络部分即可，主机位本就应有值）
                if (self::maskIP16($candidate, $bits) !== self::maskIP16($baseBin, $bits)) {
                    continue;
                }
                $serverBin = inet_pton($addrStr);
                if ($serverBin !== false && $candidate === $serverBin) {
                    // 撞上服务端地址则 +1（仍在前缀内才有效）
                    $candidate = self::addIntToIP16($candidate, 1);
                    if (self::maskIP16($candidate, $bits) !== self::maskIP16($baseBin, $bits)) {
                        continue;
                    }
                }
                $out['ipv6'] = inet_ntop($candidate).'/128';
            }
        }

        return $out;
    }

    /**
     * 16 字节大端整数加小整数（返回 16 字节串）。
     */
    public static function addIntToIP16(string $bytes16, int $off): string
    {
        $out = $bytes16;
        $carry = $off;
        for ($i = 15; $i >= 0 && $carry > 0; $i--) {
            $sum = ord($out[$i]) + ($carry % 256);
            $carry = intdiv($carry, 256) + intdiv($sum, 256);
            $out[$i] = chr($sum % 256);
        }

        return $out;
    }

    /**
     * 按前缀长度掩码 16 字节地址（返回掩码后字节串）。
     */
    public static function maskIP16(string $bytes16, int $bits): string
    {
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $b = $bits - $i * 8;
            if ($b >= 8) {
                $out .= $bytes16[$i];
            } elseif ($b <= 0) {
                $out .= "\x00";
            } else {
                $out .= chr(ord($bytes16[$i]) & (0xFF << (8 - $b) & 0xFF));
            }
        }

        return $out;
    }

    public static function guid($format = false)
    {
        if (function_exists('com_create_guid') === true) {
            return md5(trim(com_create_guid(), '{}'));
        }
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        if ($format) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        return md5(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)) . '-' . time());
    }

    /**
     * 校验订阅 token：只接受 32 位 hex 字符串。
     *
     * 传入 Request 时优先取 route token，其次取 query token，不读取 body；
     * 传入其它值时直接校验该值本身。非字符串（如 JSON 数字）一律拒绝，
     * 防止 MySQL 对 CHAR 字段的隐式数字转换导致前缀匹配绕过。
     */
    public static function validateSubscriptionToken(mixed $token): ?string
    {
        if ($token instanceof Request) {
            $token = $token->route('token') ?? $token->query('token');
        }

        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);
        if (!preg_match('/^[a-fA-F0-9]{32}$/', $token)) {
            return null;
        }

        return strtolower($token);
    }

    /**
     * 校验登录后跳转路径：只允许站内相对路径（如 /dashboard），防止开放重定向。
     * 非法输入（绝对 URL、协议相对 //、反斜杠、非字符串）一律回退到 dashboard。
     */
    public static function sanitizeRedirect(mixed $redirect): string
    {
        if (!is_string($redirect) || $redirect === '') {
            return 'dashboard';
        }
        $redirect = trim($redirect);
        // 允许 "dashboard" 这类裸路径或 "/xxx" 开头的相对路径；拒绝 //、反斜杠、scheme: 等
        if (str_starts_with($redirect, '/')) {
            if (str_contains($redirect, '\\') || str_starts_with($redirect, '//')) {
                return 'dashboard';
            }
            if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $redirect)) {
                return 'dashboard';
            }
            return $redirect;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]*$/', $redirect) && !str_contains($redirect, '\\')) {
            return $redirect;
        }
        return 'dashboard';
    }

    public static function generateOrderNo(): string
    {
        $randomChar = mt_rand(10000, 99999);
        return date('YmdHms') . substr(microtime(), 2, 6) . $randomChar;
    }

    public static function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);
        return $result['rates'][$to];
    }

    public static function randomChar($len, $special = false)
    {
        $chars = array(
            "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k",
            "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
            "w", "x", "y", "z", "A", "B", "C", "D", "E", "F", "G",
            "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R",
            "S", "T", "U", "V", "W", "X", "Y", "Z", "0", "1", "2",
            "3", "4", "5", "6", "7", "8", "9"
        );

        if ($special) {
            $chars = array_merge($chars, array(
                "!", "@", "#", "$", "?", "|", "{", "/", ":", ";",
                "%", "^", "&", "*", "(", ")", "-", "_", "[", "]",
                "}", "<", ">", "~", "+", "=", ",", "."
            ));
        }

        $charsLen = count($chars) - 1;
        shuffle($chars);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[mt_rand(0, $charsLen)];
        }
        return $str;
    }

    public static function wrapIPv6($addr) {
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return "[$addr]";
        } else {
            return $addr;
        }
    }

    public static function multiPasswordVerify($algo, $salt, $password, $hash)
    {
        switch($algo) {
            case 'md5': return md5($password) === $hash;
            case 'sha256': return hash('sha256', $password) === $hash;
            case 'md5salt': return md5($password . $salt) === $hash;
            case 'sha256salt': return hash('sha256', $password . $salt) === $hash;
            default: return password_verify($password, $hash);
        }
    }

    public static function emailSuffixVerify($email, $suffixs)
    {
        $suffix = preg_split('/@/', $email)[1];
        if (!$suffix) return false;
        if (!is_array($suffixs)) {
            $suffixs = preg_split('/,/', $suffixs);
        }
        if (!in_array($suffix, $suffixs)) return false;
        return true;
    }

    public static function trafficConvert(float $byte)
    {
        $kb = 1024;
        $mb = 1048576;
        $gb = 1073741824;
        if ($byte > $gb) {
            return round($byte / $gb, 2) . ' GB';
        } else if ($byte > $mb) {
            return round($byte / $mb, 2) . ' MB';
        } else if ($byte > $kb) {
            return round($byte / $kb, 2) . ' KB';
        } else if ($byte < 0) {
            return 0;
        } else {
            return round($byte, 2) . ' B';
        }
    }

    public static function getSubscribeUrl(string $token, $subscribeUrl = null)
    {
        $path = route('client.subscribe', ['token' => $token], false);
        
        if ($subscribeUrl) {
            $finalUrl = rtrim($subscribeUrl, '/') . $path;
            return HookManager::filter('subscribe.url', $finalUrl);
        }
        
        $urlString = (string)admin_setting('subscribe_url', '');
        $subscribeUrlList = $urlString ? explode(',', $urlString) : [];
        
        if (empty($subscribeUrlList)) {
            return HookManager::filter('subscribe.url', url($path));
        }
        
        $selectedUrl = self::replaceByPattern(Arr::random($subscribeUrlList));
        $finalUrl = rtrim($selectedUrl, '/') . $path;
        
        return HookManager::filter('subscribe.url', $finalUrl);
    }

    public static function randomPort($range): int {
        $portRange = explode('-', (string) $range, 2);
        $min = (int) ($portRange[0] ?? 0);
        $max = (int) ($portRange[1] ?? $portRange[0] ?? 0);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return random_int($min, $max);
    }

    public static function base64EncodeUrlSafe($data)
    {
        $encoded = base64_encode($data);
        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }

    /**
     * RFC 9000 §16 QUIC 可变长整数编码（TrustTunnel deeplink Tag/Length 用）。
     * 2 高位决定字节数：00=1B, 01=2B, 10=4B, 11=8B，大端序。
     */
    public static function encodeTlsVarint(int $n): string
    {
        if ($n < 0) {
            $n = 0;
        }
        if ($n < (1 << 6)) {
            return chr($n);
        }
        if ($n < (1 << 14)) {
            return chr(0x40 | ($n >> 8)).chr($n & 0xFF);
        }
        if ($n < (1 << 30)) {
            return chr(0x80 | (($n >> 24) & 0xFF)).chr(($n >> 16) & 0xFF).chr(($n >> 8) & 0xFF).chr($n & 0xFF);
        }
        // 8 字节（PHP 64 位 int 足够覆盖 deeplink 长度范围）
        $out = chr(0xC0 | (($n >> 56) & 0xFF));
        for ($shift = 48; $shift >= 0; $shift -= 8) {
            $out .= chr(($n >> $shift) & 0xFF);
        }
        return $out;
    }

    /**
     * TrustTunnel deeplink TLV 条目：Tag(varint) + Length(varint) + Value。
     */
    public static function encodeTrustTunnelTlv(int $tag, string $value): string
    {
        return self::encodeTlsVarint($tag).self::encodeTlsVarint(strlen($value)).$value;
    }

    /**
     * 将 REALITY / X25519 密钥规范化为 base64.RawURLEncoding（无填充、URL 安全）。
     * Xray / mihomo / Clash.Meta 等客户端只接受该格式，标准 Base64（含 + / =）会报
     * "invalid REALITY public key"。
     */
    public static function normalizeRealityKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $key = trim($key);
        if ($key === '') {
            return null;
        }

        // 已是合法 RawURL（32 字节）则直接返回
        $raw = self::decodeRealityKeyBytes($key);
        if ($raw === null) {
            return $key; // 无法解码时原样返回，避免误伤
        }

        return self::base64EncodeUrlSafe($raw);
    }

    /**
     * 尝试按多种 Base64 变体解码 32 字节 X25519 密钥。
     */
    public static function decodeRealityKeyBytes(string $key): ?string
    {
        $candidates = [
            $key,
            // 补齐标准 Base64 填充后再解
            str_pad(strtr($key, '-_', '+/'), (int) (ceil(strlen($key) / 4) * 4), '=', STR_PAD_RIGHT),
            str_pad($key, (int) (ceil(strlen($key) / 4) * 4), '=', STR_PAD_RIGHT),
        ];

        foreach ($candidates as $candidate) {
            $decoded = base64_decode($candidate, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 规范化 reality_settings / tls_settings 中的 public_key / private_key。
     *
     * @param  array|null  $settings
     * @return array|null
     */
    public static function normalizeRealitySettings(?array $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        foreach (['public_key', 'private_key'] as $field) {
            if (!empty($settings[$field]) && is_string($settings[$field])) {
                $normalized = self::normalizeRealityKey($settings[$field]);
                if ($normalized !== null) {
                    $settings[$field] = $normalized;
                }
            }
        }

        return $settings;
    }

    /**
     * 根据规则替换域名中对应的字符串
     *
     * @param string $input 用户输入的字符串
     * @return string 替换后的字符串
     */
    public static function replaceByPattern($input)
    {
        $patterns = [
            '/\[(\d+)-(\d+)\]/' => function ($matches) {
                $min = intval($matches[1]);
                $max = intval($matches[2]);
                if ($min > $max) {
                    list($min, $max) = [$max, $min];
                }
                $randomNumber = rand($min, $max);
                return $randomNumber;
            },
            '/\[uuid\]/' => function () {
                return  self::guid(true);
            }
        ];
        foreach ($patterns as $pattern => $callback) {
            $input = preg_replace_callback($pattern, $callback, $input);
        }
        return $input;
    }

    public static function getIpByDomainName($domain) {
        return gethostbynamel($domain) ?: [];
    }
    
    /**
     * 系统配置中可编辑的 uTLS 具体指纹列表（已规范化）。
     * 会剔除 random / randomized；空配置时回退默认具体指纹。
     *
     * @return list<string>
     */
    public static function getUtlsFingerprints(): array
    {
        $list = admin_setting('utls_fingerprints', Dict::UTLS_FINGERPRINTS_DEFAULT);
        if (!is_array($list)) {
            $list = Dict::UTLS_FINGERPRINTS_DEFAULT;
        }

        $meta = array_fill_keys(Dict::UTLS_FINGERPRINT_META, true);
        $normalized = [];
        foreach ($list as $item) {
            $value = strtolower(trim((string) $item));
            // 元选项不可配置，即使库里残留也过滤掉
            if ($value === '' || isset($meta[$value])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) {
                continue;
            }
            $normalized[$value] = $value;
        }

        if ($normalized === []) {
            return Dict::UTLS_FINGERPRINTS_DEFAULT;
        }

        return array_values($normalized);
    }

    /**
     * 节点表单 / 校验用完整指纹列表：具体指纹 + 固定元选项。
     *
     * @return list<string>
     */
    public static function getUtlsFingerprintsForSelect(): array
    {
        return array_values(array_unique(array_merge(
            self::getUtlsFingerprints(),
            Dict::UTLS_FINGERPRINT_META
        )));
    }

    /**
     * 节点表单下拉 options：value => label
     * 始终包含 random / randomized（系统设置中不可编辑）。
     *
     * @return array<string, string>
     */
    public static function getUtlsFingerprintOptions(): array
    {
        $options = [];
        foreach (self::getUtlsFingerprintsForSelect() as $fp) {
            $options[$fp] = match ($fp) {
                'ios' => 'iOS',
                'qq' => 'QQ',
                'random' => 'Random',
                'randomized' => 'Randomized',
                default => ucfirst($fp),
            };
        }
        return $options;
    }

    /**
     * random 元选项的实际抽样池（仅具体指纹）
     *
     * @return list<string>
     */
    public static function getUtlsRandomPool(): array
    {
        $pool = self::getUtlsFingerprints();

        return $pool !== []
            ? $pool
            : Dict::UTLS_FINGERPRINTS_DEFAULT;
    }

    public static function getTlsFingerprint($utls = null)
    {

        if (is_array($utls) || is_object($utls)) {
            if (!data_get($utls, 'enabled')) {
                return null;
            }
            $fingerprint = data_get($utls, 'fingerprint', 'chrome');
            if ($fingerprint !== 'random') {
                return $fingerprint;
            }
        }

        return Arr::random(self::getUtlsRandomPool());
    }

    public static function normalizeEchSettings($ech = null): ?array
    {
        if (!is_array($ech) && !is_object($ech)) {
            return null;
        }

        if (!data_get($ech, 'enabled')) {
            return null;
        }

        return array_filter([
            'enabled' => true,
            'config' => self::trimToNull(data_get($ech, 'config')),
            'query_server_name' => self::trimToNull(data_get($ech, 'query_server_name')),
            'key' => self::trimToNull(data_get($ech, 'key')),
            'key_path' => self::trimToNull(data_get($ech, 'key_path')),
            'config_path' => self::trimToNull(data_get($ech, 'config_path')),
        ], static fn($value) => $value !== null);
    }

    public static function toMihomoEchConfig(?string $config): ?string
    {
        $config = self::trimToNull($config);
        if (!$config) {
            return null;
        }

        if (str_starts_with($config, '-----BEGIN')) {
            if (preg_match('/-----BEGIN ECH CONFIGS-----\s*(.*?)\s*-----END ECH CONFIGS-----/s', $config, $matches)) {
                return preg_replace('/\s+/', '', $matches[1]);
            }
            return null;
        }

        return preg_replace('/\s+/', '', $config);
    }

    public static function trimToNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    public static function encodeURIComponent($str) {
        $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
        return strtr(rawurlencode($str), $revert);
    }

    public static function getEmailSuffix(): array|bool
    {
        $suffix = admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
    
    /**
     * convert the transfer_enable to GB
     * @param float $transfer_enable
     * @return float
     */
    public static function transferToGB(float $transfer_enable): float
    {
        return $transfer_enable / 1073741824;
    }

    /**
     * 转义 Telegram Markdown 特殊字符
     * @param string $text
     * @return string
     */
    public static function escapeMarkdown(string $text): string
    {
        return str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $text);
    }

    /**
     * 元 → 分（四舍五入到整数分，避免 19.99*100 浮点截断）。
     */
    public static function yuanToCents(int|float|string|null $yuan): int
    {
        if ($yuan === null || $yuan === '') {
            return 0;
        }

        return (int) round(((float) $yuan) * 100);
    }

    /**
     * 按百分比计算金额（分），向零截断为整数分。
     * 避免 699 * 20% = 139.8 这类 float 写入 MySQL INTEGER 列失败。
     */
    public static function percentOfCents(int $cents, int|float|string|null $rate): int
    {
        if ($cents === 0 || $rate === null || $rate === '') {
            return 0;
        }

        // 百分比最多保留两位小数（如 12.5%），再以整数运算避免 float 误差
        $rateBasisPoints = (int) round(((float) $rate) * 100); // 1% = 100

        return intdiv($cents * $rateBasisPoints, 10000);
    }
}
