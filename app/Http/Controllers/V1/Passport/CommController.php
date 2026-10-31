<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\CaptchaService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
                // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError);
        }

        $email = $request->input('email');

        // 安全检查：查找用户，确保投递的邮箱与 DB 中存储的邮箱一致，
        // 防止 IDN 变体邮箱（如 admin@éxample.com）被用于攻击 admin@example.com
        $user = User::byEmail($email)->first();

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            if (!$user) {
                $allowedSuffixes = Helper::getEmailSuffix();
                $emailSuffix = substr(strrchr($email, '@'), 1);

                if (!in_array($emailSuffix, $allowedSuffixes)) {
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        // 冷却时间（可配置，默认 60 秒）
        $cooldown = max(10, (int) admin_setting('email_verify_cooldown', 60));
        $cooldownKey = CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email);
        if (Cache::get($cooldownKey)) {
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }

        // 如果用户存在，用 DB 中存储的邮箱作为收件人，防止变体邮箱攻击
        $recipientEmail = $user ? $user->email : $email;

        $code = rand(100000, 999999);
        $subject = admin_setting('app_name', 'Fboard') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $recipientEmail,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'Fboard'),
                'code' => $code,
                'url' => admin_setting('app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $recipientEmail), $code, 300);
        Cache::put($cooldownKey, time(), $cooldown);
        return $this->success(true);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
