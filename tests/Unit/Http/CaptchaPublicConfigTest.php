<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Guest\CommController as GuestCommController;
use App\Http\Controllers\V2\Client\AppController as ClientAppController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CaptchaPublicConfigTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_PUBLIC_KEYS = [
        'recaptcha_site_key',
        'recaptcha_v3_site_key',
        'recaptcha_v3_score_threshold',
        'turnstile_site_key',
    ];

    private const TYPE_KEYS = [
        'recaptcha' => ['recaptcha_site_key'],
        'recaptcha-v3' => ['recaptcha_v3_site_key', 'recaptcha_v3_score_threshold'],
        'turnstile' => ['turnstile_site_key'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        admin_setting([
            'captcha_enable' => 1,
            'captcha_type' => 'recaptcha',
            'recaptcha_site_key' => 'v2-site',
            'recaptcha_v3_site_key' => 'v3-site',
            'recaptcha_v3_score_threshold' => 0.7,
            'turnstile_site_key' => 'turnstile-site',
        ]);
    }

    public function test_guest_config_only_exposes_active_captcha_type(): void
    {
        foreach (self::TYPE_KEYS as $type => $expectedKeys) {
            admin_setting(['captcha_type' => $type]);

            $data = app(GuestCommController::class)->config()->getData(true)['data'];

            $this->assertSame($type, $data['captcha_type']);
            $this->assertPublicKeys($data, $expectedKeys, $type);
        }
    }

    public function test_client_app_config_only_exposes_active_captcha_type(): void
    {
        foreach (self::TYPE_KEYS as $type => $expectedKeys) {
            admin_setting(['captcha_type' => $type]);

            $data = app(ClientAppController::class)
                ->getConfig(Request::create('/'))
                ->getData(true)['data']['security_config'];

            $this->assertSame($type, $data['captcha_type']);
            $this->assertPublicKeys($data, $expectedKeys, $type);
        }
    }

    private function assertPublicKeys(array $data, array $expectedKeys, string $type): void
    {
        foreach (self::ALL_PUBLIC_KEYS as $key) {
            if (in_array($key, $expectedKeys, true)) {
                $this->assertArrayHasKey($key, $data, "{$type} 应返回 {$key}");
            } else {
                $this->assertArrayNotHasKey($key, $data, "{$type} 不应返回 {$key}");
            }
        }
    }
}
