<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class LoginService
{
    public function __construct(
        private readonly LoginLogService $loginLogService
    ) {
    }

    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @param string|null $ip 客户端 IP
     * @param string|null $userAgent User-Agent
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $limitEnabled = (int) admin_setting('password_limit_enable', true);
        $limitCount = (int) admin_setting('password_limit_count', 5);
        $limitExpire = (int) admin_setting('password_limit_expire', 60);
        // 按 IP 的失败计数键（$ip 为空时不做 IP 维度限制）
        $ipKey = ($ip !== null && $ip !== '') ? CacheKey::get('PASSWORD_ERROR_LIMIT_IP', $ip) : null;

        // 检查密码错误限制（邮箱 + IP 双维度，防止对大量邮箱做密码喷洒）
        if ($limitEnabled) {
            $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            $ipErrorCount = $ipKey ? (int) Cache::get($ipKey, 0) : 0;
            if ($passwordErrorCount >= $limitCount || ($ipKey && $ipErrorCount >= $limitCount)) {
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => $limitExpire
                        ])
                    ]
                ];
            }
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            // 用户不存在同样计入 IP 维度，避免通过枚举差异规避限流
            if ($limitEnabled) {
                $this->recordPasswordFailure($email, $ipKey, $limitExpire);
            }
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            // 增加密码错误计数（邮箱 + IP）
            if ($limitEnabled) {
                $this->recordPasswordFailure($email, $ipKey, $limitExpire);
            }
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        // 登录成功：清除该 IP 的失败计数，避免共享出口 IP 的正常用户被误伤
        if ($ipKey) {
            Cache::forget($ipKey);
        }

        $this->loginLogService->recordLogin(
            $user,
            $ip ?? '',
            $userAgent,
            UserLoginLog::METHOD_PASSWORD
        );

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 记录一次密码错误：同时累加邮箱维度与（可选的）IP 维度计数。
     */
    private function recordPasswordFailure(string $email, ?string $ipKey, int $limitExpire): void
    {
        $ttl = 60 * max(1, $limitExpire);

        $emailKey = CacheKey::get('PASSWORD_ERROR_LIMIT', $email);
        Cache::put($emailKey, (int) Cache::get($emailKey, 0) + 1, $ttl);

        if ($ipKey) {
            Cache::put($ipKey, (int) Cache::get($ipKey, 0) + 1, $ttl);
        }
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        // 检查重置请求限制
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        $forgetRequestLimit = (int) Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        // 验证邮箱验证码
        $cachedEmailCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        if ($cachedEmailCode === null || !hash_equals((string) $cachedEmailCode, $emailCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            return [false, [400, __('Incorrect email verification code')]];
        }

        // 查找用户
        $user = User::byEmail($email)->first();
        if (!$user) {
            return [false, [400, __('This email is not registered in the system')]];
        }

        // 更新密码
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;

        if (!$user->save()) {
            return [false, [500, __('Reset failed')]];
        }

        // 密码已变：作废全部已签发 Sanctum 会话，防止旧 token 继续访问
        (new AuthService($user))->removeAllSessions();

        HookManager::call('user.password.reset.after', $user);

        // 清除邮箱验证码
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));

        return [true, true];
    }


    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        Cache::put($key, $user->id, 60);

        $redirect = $redirect ?: 'dashboard';
        $loginRedirect = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }
}