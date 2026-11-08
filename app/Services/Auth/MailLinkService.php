<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理邮件链接登录逻辑
     *
     * @param string $email 用户邮箱
     * @param string|null $redirect 重定向地址
     * @return array 返回处理结果
     */
    public function handleMailLink(string $email, ?string $redirect = null): array
    {
        if (!(int) admin_setting('login_with_mail_link_enable')) {
            return [false, [404, null]];
        }

        // 冷却时间（可配置，默认 60 秒）
        $cooldown = max(10, (int) admin_setting('email_verify_cooldown', 60));
        $cooldownKey = CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email);
        if (Cache::get($cooldownKey)) {
            return [false, [429, __('Sending frequently, please try again later')]];
        }

        $user = User::byEmail($email)->first();
        if (!$user) {
            return [true, true]; // 成功但用户不存在，保护用户隐私
        }

        // 安全检查：用 DB 中存储的邮箱作为收件人，防止 IDN 变体邮箱攻击
        $recipientEmail = $user->email;

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put($cooldownKey, time(), $cooldown);

        $redirectUrl = '/#/login?verify=' . $code . '&redirect=' . rawurlencode(Helper::sanitizeRedirect($redirect));
        if (admin_setting('app_url')) {
            $link = admin_setting('app_url') . $redirectUrl;
        } else {
            $link = url($redirectUrl);
        }

        $this->sendMailLinkEmail($recipientEmail, $link);

        return [true, true];
    }

    /**
     * 发送邮件链接登录邮件
     *
     * @param string $recipientEmail 收件人邮箱
     * @param string $link 登录链接
     * @return void
     */
    private function sendMailLinkEmail(string $recipientEmail, string $link): void
    {
        SendEmailJob::dispatch([
            'email' => $recipientEmail,
            'subject' => __('Login to :name', [
                'name' => admin_setting('app_name', 'Fboard')
            ]),
            'template_name' => 'mailLogin',
            'template_value' => [
                'name' => admin_setting('app_name', 'Fboard'),
                'link' => $link,
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    /**
     * 处理Token登录
     * 
     * @param string $token 登录令牌
     * @return int|null 用户ID或null
     */
    public function handleTokenLogin(string $token): ?int
    {
        $key = CacheKey::get('TEMP_TOKEN', $token);
        $userId = Cache::get($key);

        if (!$userId) {
            return null;
        }

        $user = User::find($userId);

        if (!$user || $user->banned) {
            return null;
        }

        Cache::forget($key);

        return $userId;
    }
}