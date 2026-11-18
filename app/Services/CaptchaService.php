<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReCaptcha\ReCaptcha;
use Throwable;

class CaptchaService
{
    /**
     * 验证人机验证码
     *
     * @param Request $request 请求对象
     * @param string|null $expectedAction reCAPTCHA v3 期望的 action（由调用方指定，防止跨 action 重放 token）
     * @return array [是否通过, 错误消息]
     */
    public function verify(Request $request, ?string $expectedAction = null): array
    {
        if (!(int) admin_setting('captcha_enable', 0)) {
            return [true, null];
        }

        $captchaType = admin_setting('captcha_type', 'recaptcha');

        return match ($captchaType) {
            'turnstile' => $this->verifyTurnstile($request),
            'recaptcha-v3' => $this->verifyRecaptchaV3($request, $expectedAction),
            'recaptcha' => $this->verifyRecaptcha($request),
            default => [false, [400, __('Invalid captcha type')]]
        };
    }

    /**
     * 验证 Cloudflare Turnstile
     */
    private function verifyTurnstile(Request $request): array
    {
        $token = $request->input('turnstile_token');
        if (!is_string($token) || $token === '') {
            return $this->invalid();
        }

        try {
            $response = Http::timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => admin_setting('turnstile_secret_key'),
                'response' => $token,
                'remoteip' => $request->ip()
            ]);
        } catch (Throwable $e) {
            Log::warning('Turnstile verification request failed: ' . $e->getMessage());
            return $this->invalid();
        }

        if (!$response->successful()) {
            Log::warning('Turnstile verification returned HTTP ' . $response->status());
            return $this->invalid();
        }

        $result = $response->json();
        if (!is_array($result) || !($result['success'] ?? false)) {
            return $this->invalid();
        }

        return [true, null];
    }

    /**
     * 验证 Google reCAPTCHA v3
     *
     * 绑定域名与 action，并改用官方库的分数阈值校验，避免跨站/跨 action 的 token 被接受。
     */
    private function verifyRecaptchaV3(Request $request, ?string $expectedAction): array
    {
        $token = $request->input('recaptcha_v3_token');
        if (!is_string($token) || $token === '') {
            return $this->invalid();
        }

        try {
            $recaptcha = $this->makeReCaptcha((string) admin_setting('recaptcha_v3_secret_key'));
            $recaptcha->setExpectedHostname($this->expectedHostname($request));
            if ($expectedAction !== null && $expectedAction !== '') {
                $recaptcha->setExpectedAction($expectedAction);
            }
            $recaptcha->setScoreThreshold((float) admin_setting('recaptcha_v3_score_threshold', 0.5));
            $recaptchaResp = $recaptcha->verify($token, $request->ip());
        } catch (Throwable $e) {
            Log::warning('reCAPTCHA v3 verification failed: ' . $e->getMessage());
            return $this->invalid();
        }

        return $recaptchaResp->isSuccess() ? [true, null] : $this->invalid();
    }

    /**
     * 验证 Google reCAPTCHA v2
     */
    private function verifyRecaptcha(Request $request): array
    {
        $token = $request->input('recaptcha_data');
        if (!is_string($token) || $token === '') {
            return $this->invalid();
        }

        try {
            $recaptcha = $this->makeReCaptcha((string) admin_setting('recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($token, $request->ip());
        } catch (Throwable $e) {
            Log::warning('reCAPTCHA verification failed: ' . $e->getMessage());
            return $this->invalid();
        }

        return $recaptchaResp->isSuccess() ? [true, null] : $this->invalid();
    }

    /**
     * reCAPTCHA 实例工厂：便于测试注入自定义 RequestMethod。
     */
    protected function makeReCaptcha(string $secret): ReCaptcha
    {
        return new ReCaptcha($secret);
    }

    /**
     * 期望的主机名：优先取站点配置的域名，否则用当前请求 Host。
     */
    private function expectedHostname(Request $request): string
    {
        $appUrl = admin_setting('app_url');
        if (is_string($appUrl) && $appUrl !== '') {
            $host = parse_url($appUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        return $request->getHost();
    }

    private function invalid(): array
    {
        return [false, [400, __('Invalid code is incorrect')]];
    }
}
