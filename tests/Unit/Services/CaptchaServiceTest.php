<?php

namespace Tests\Unit\Services;

use App\Services\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ReCaptcha\ReCaptcha;
use ReCaptcha\RequestMethod;
use ReCaptcha\RequestParameters;
use Tests\TestCase;

class CaptchaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        admin_setting([
            'captcha_enable' => 1,
            'captcha_type' => 'recaptcha',
            'recaptcha_key' => 'v2-secret',
            'recaptcha_site_key' => 'v2-site',
            'recaptcha_v3_secret_key' => 'v3-secret',
            'recaptcha_v3_site_key' => 'v3-site',
            'recaptcha_v3_score_threshold' => 0.5,
            'turnstile_secret_key' => 'turnstile-secret',
            'turnstile_site_key' => 'turnstile-site',
        ]);
    }

    public function test_disabled_captcha_passes_without_token(): void
    {
        admin_setting(['captcha_enable' => 0]);

        [$ok, $error] = app(CaptchaService::class)->verify($this->request());

        $this->assertTrue($ok);
        $this->assertNull($error);
    }

    public function test_turnstile_accepts_valid_token(): void
    {
        admin_setting(['captcha_type' => 'turnstile']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        [$ok] = app(CaptchaService::class)->verify($this->request(['turnstile_token' => 'token']));

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['response'] === 'token'
            && $request['secret'] === 'turnstile-secret');
    }

    public function test_turnstile_rejects_missing_token(): void
    {
        admin_setting(['captcha_type' => 'turnstile']);

        [$ok, $error] = app(CaptchaService::class)->verify($this->request());

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    public function test_turnstile_rejects_non_json_response(): void
    {
        admin_setting(['captcha_type' => 'turnstile']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response('not json', 200),
        ]);

        [$ok, $error] = app(CaptchaService::class)->verify($this->request(['turnstile_token' => 'token']));

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    public function test_turnstile_rejects_connection_failure(): void
    {
        admin_setting(['captcha_type' => 'turnstile']);
        Http::fake(fn () => throw new ConnectionException('down'));

        [$ok, $error] = app(CaptchaService::class)->verify($this->request(['turnstile_token' => 'token']));

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    public function test_recaptcha_v3_accepts_matching_action_hostname_and_score(): void
    {
        admin_setting(['captcha_type' => 'recaptcha-v3']);

        [$ok] = $this->serviceWithRecaptcha($this->v3Response('register', 0.9, 'localhost'))
            ->verify($this->request(['recaptcha_v3_token' => 'token']), 'register');

        $this->assertTrue($ok);
    }

    public function test_recaptcha_v3_rejects_wrong_action(): void
    {
        admin_setting(['captcha_type' => 'recaptcha-v3']);

        [$ok, $error] = $this->serviceWithRecaptcha($this->v3Response('homepage', 0.9, 'localhost'))
            ->verify($this->request(['recaptcha_v3_token' => 'token']), 'register');

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    public function test_recaptcha_v3_rejects_low_score(): void
    {
        admin_setting(['captcha_type' => 'recaptcha-v3']);

        [$ok] = $this->serviceWithRecaptcha($this->v3Response('register', 0.1, 'localhost'))
            ->verify($this->request(['recaptcha_v3_token' => 'token']), 'register');

        $this->assertFalse($ok);
    }

    public function test_recaptcha_v3_rejects_wrong_hostname(): void
    {
        admin_setting(['captcha_type' => 'recaptcha-v3']);

        [$ok] = $this->serviceWithRecaptcha($this->v3Response('register', 0.9, 'evil.example'))
            ->verify($this->request(['recaptcha_v3_token' => 'token']), 'register');

        $this->assertFalse($ok);
    }

    public function test_recaptcha_v3_fails_gracefully_when_secret_is_missing(): void
    {
        admin_setting([
            'captcha_type' => 'recaptcha-v3',
            'recaptcha_v3_secret_key' => '',
        ]);

        [$ok, $error] = $this->serviceWithRecaptcha($this->v3Response('register', 0.9, 'localhost'))
            ->verify($this->request(['recaptcha_v3_token' => 'token']), 'register');

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    public function test_recaptcha_v2_accepts_valid_token(): void
    {
        [$ok] = $this->serviceWithRecaptcha('{"success":true,"hostname":"localhost"}')
            ->verify($this->request(['recaptcha_data' => 'token']));

        $this->assertTrue($ok);
    }

    public function test_recaptcha_v2_rejects_missing_token(): void
    {
        [$ok, $error] = app(CaptchaService::class)->verify($this->request());

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    public function test_unknown_captcha_type_is_rejected(): void
    {
        admin_setting(['captcha_type' => 'unknown']);

        [$ok, $error] = app(CaptchaService::class)->verify($this->request(['turnstile_token' => 'token']));

        $this->assertFalse($ok);
        $this->assertSame(400, $error[0]);
    }

    private function v3Response(string $action, float $score, string $hostname): string
    {
        return (string) json_encode([
            'success' => true,
            'score' => $score,
            'action' => $action,
            'hostname' => $hostname,
            'challenge_ts' => gmdate('Y-m-d\TH:i:sZ'),
        ]);
    }

    private function request(array $input = []): Request
    {
        return Request::create('/api/v1/passport/auth/register', 'POST', $input, [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
    }

    private function serviceWithRecaptcha(string $json): CaptchaService
    {
        return new class($json) extends CaptchaService
        {
            public function __construct(private readonly string $json)
            {
            }

            protected function makeReCaptcha(string $secret): ReCaptcha
            {
                return new ReCaptcha($secret, new FakeRecaptchaRequestMethod($this->json));
            }
        };
    }
}

class FakeRecaptchaRequestMethod implements RequestMethod
{
    public function __construct(private readonly string $body)
    {
    }

    public function submit(RequestParameters $params)
    {
        return $this->body;
    }
}
