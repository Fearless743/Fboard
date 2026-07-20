<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserLoginLog;

class LoginLogService
{
    /**
     * 记录一次成功登录：更新用户 last_login_* 并写入历史（每用户最多保留 20 条）。
     */
    public function recordLogin(
        User $user,
        string $ip,
        ?string $userAgent,
        string $method
    ): void {
        $ip = $this->normalizeIp($ip);
        $userAgent = $this->normalizeUserAgent($userAgent);

        $user->last_login_at = time();
        $user->last_login_ip = $ip !== '' ? $ip : null;
        $user->save();

        UserLoginLog::create([
            'user_id' => $user->id,
            'ip' => $ip !== '' ? $ip : '0.0.0.0',
            'user_agent' => $userAgent,
            'method' => $method,
        ]);

        $this->trimHistory($user->id);
    }

    /**
     * 注册时写入 register_ip（仅一次），并记一条 register 登录历史。
     */
    public function recordRegister(User $user, string $ip, ?string $userAgent): void
    {
        $ip = $this->normalizeIp($ip);
        if ($ip !== '' && empty($user->register_ip)) {
            $user->register_ip = $ip;
            $user->save();
        }

        $this->recordLogin($user, $ip, $userAgent, UserLoginLog::METHOD_REGISTER);
    }

    private function trimHistory(int $userId): void
    {
        $keepIds = UserLoginLog::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(UserLoginLog::MAX_PER_USER)
            ->pluck('id');

        if ($keepIds->isEmpty()) {
            return;
        }

        UserLoginLog::where('user_id', $userId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || strlen($ip) > 45) {
            return '';
        }
        return $ip;
    }

    private function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return null;
        }
        return mb_substr($userAgent, 0, 512);
    }
}
