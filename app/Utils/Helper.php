<?php

namespace App\Utils;

use App\Services\Plugin\HookManager;
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
