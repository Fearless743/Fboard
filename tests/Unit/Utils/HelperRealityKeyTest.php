<?php

namespace Tests\Unit\Utils;

use App\Utils\Helper;
use PHPUnit\Framework\TestCase;

class HelperRealityKeyTest extends TestCase
{
    public function test_normalize_reality_key_converts_standard_base64_to_raw_url(): void
    {
        // 32 zero bytes: standard Base64 has trailing '=' which clients reject
        $raw = str_repeat("\0", 32);
        $std = base64_encode($raw);
        $this->assertStringEndsWith('=', $std);

        $normalized = Helper::normalizeRealityKey($std);
        $this->assertSame(Helper::base64EncodeUrlSafe($raw), $normalized);
        $this->assertStringNotContainsString('=', $normalized);
        $this->assertStringNotContainsString('+', $normalized);
        $this->assertStringNotContainsString('/', $normalized);
    }

    public function test_normalize_reality_key_is_idempotent_for_raw_url(): void
    {
        $raw = random_bytes(32);
        $url = Helper::base64EncodeUrlSafe($raw);

        $this->assertSame($url, Helper::normalizeRealityKey($url));
        $this->assertSame($url, Helper::normalizeRealityKey(Helper::normalizeRealityKey($url)));
    }

    public function test_normalize_reality_key_handles_plus_and_slash(): void
    {
        // Craft bytes that produce + and / in standard Base64
        $raw = hex2bin(str_repeat('fb', 32)); // 0xfb -> high chance of +/
        $std = base64_encode($raw);
        $normalized = Helper::normalizeRealityKey($std);

        $this->assertNotNull($normalized);
        $this->assertSame(32, strlen(Helper::decodeRealityKeyBytes($normalized)));
        $this->assertSame($raw, Helper::decodeRealityKeyBytes($normalized));
        $this->assertStringNotContainsString('=', $normalized);
        $this->assertStringNotContainsString('+', $normalized);
        $this->assertStringNotContainsString('/', $normalized);
    }

    public function test_normalize_reality_settings_rewrites_keys(): void
    {
        $rawPub = random_bytes(32);
        $rawPriv = random_bytes(32);
        $settings = [
            'public_key' => base64_encode($rawPub),
            'private_key' => base64_encode($rawPriv),
            'short_id' => 'abcd',
            'server_name' => 'www.example.com',
        ];

        $normalized = Helper::normalizeRealitySettings($settings);

        $this->assertSame(Helper::base64EncodeUrlSafe($rawPub), $normalized['public_key']);
        $this->assertSame(Helper::base64EncodeUrlSafe($rawPriv), $normalized['private_key']);
        $this->assertSame('abcd', $normalized['short_id']);
        $this->assertSame('www.example.com', $normalized['server_name']);
    }

    public function test_normalize_reality_key_returns_null_for_empty(): void
    {
        $this->assertNull(Helper::normalizeRealityKey(null));
        $this->assertNull(Helper::normalizeRealityKey(''));
        $this->assertNull(Helper::normalizeRealityKey('   '));
    }
}
