<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\Auth\LoginLogService;
use App\Services\Auth\PasskeyService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;

class PasskeyController extends Controller
{
    public function __construct(
        private readonly PasskeyService $passkeyService,
        private readonly LoginLogService $loginLogService
    ) {
    }

    /**
     * 第二步：获取登录断言 options（需第一步签发的 ticket，无需登录态）。
     */
    public function authOptions(Request $request)
    {
        $ticket = (string) $request->input('ticket', '');
        if ($ticket === '') {
            return $this->fail([400, __('Invalid request')]);
        }
        $data = $this->passkeyService->authenticationOptionsForTicket(
            $ticket,
            (string) ($request->header('Origin') ?? '')
        );
        return $this->success($data);
    }

    /**
     * 第二步：校验断言，签发 token。
     */
    public function authVerify(Request $request)
    {
        $ticket = (string) $request->input('ticket', '');
        $credential = $request->input('credential');
        if ($ticket === '' || !is_array($credential)) {
            return $this->fail([400, __('Invalid request')]);
        }

        try {
            $user = $this->passkeyService->verifyAuthentication(
                $ticket,
                $credential,
                (string) ($request->header('Origin') ?? '')
            );
        } catch (\Throwable $e) {
            report($e);
            return $this->fail([400, __('Passkey verification failed')]);
        }

        if (!$user->is_admin) {
            return $this->fail([403, __('Unauthorized')]);
        }

        $this->loginLogService->recordLogin(
            $user,
            (string) $request->ip(),
            $request->userAgent(),
            UserLoginLog::METHOD_PASSKEY
        );
        HookManager::call('user.login.after', $user);

        return $this->success((new AuthService($user))->generateAuthData());
    }

    /**
     * 首次绑定：无凭证管理员用 ticket 获取注册 options。
     */
    public function registerOptionsSetup(Request $request)
    {
        $ticket = (string) $request->input('ticket', '');
        if ($ticket === '') {
            return $this->fail([400, __('Invalid request')]);
        }
        $user = $this->resolveTicketUser($ticket);
        if (!$user) {
            return $this->fail([400, __('Login session expired, please login again')]);
        }
        if ($this->passkeyService->hasPasskey($user)) {
            return $this->fail([400, __('Passkey already exists, please login with passkey')]);
        }
        return $this->success(
            $this->passkeyService->registrationOptions($user, (string) ($request->header('Origin') ?? ''))
        );
    }

    public function registerVerifySetup(Request $request)
    {
        $ticket = (string) $request->input('ticket', '');
        $credential = $request->input('credential');
        if ($ticket === '' || !is_array($credential)) {
            return $this->fail([400, __('Invalid request')]);
        }
        $user = $this->resolveTicketUser($ticket);
        if (!$user) {
            return $this->fail([400, __('Login session expired, please login again')]);
        }
        if ($this->passkeyService->hasPasskey($user)) {
            return $this->fail([400, __('Passkey already exists, please login with passkey')]);
        }
        try {
            $passkey = $this->passkeyService->verifyRegistration(
                $user,
                $credential,
                (string) ($request->header('Origin') ?? ''),
                (string) $request->input('name', '')
            );
        } catch (\Throwable $e) {
            report($e);
            return $this->fail([400, __('Passkey verification failed')]);
        }
        // 绑定成功后签发新的登录 ticket，前端继续走 auth 完成第二步拿 token
        $newTicket = $this->passkeyService->issueLoginTicket($user);
        return $this->success(['id' => $passkey->id, 'ticket' => $newTicket]);
    }

    /**
     * 已登录管理员：获取注册 options。
     */
    public function registerOptions(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED], 401);
        }
        return $this->success(
            $this->passkeyService->registrationOptions($user, (string) ($request->header('Origin') ?? ''))
        );
    }

    public function registerVerify(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED], 401);
        }
        $credential = $request->input('credential');
        if (!is_array($credential)) {
            return $this->fail([400, __('Invalid request')]);
        }
        try {
            $passkey = $this->passkeyService->verifyRegistration(
                $user,
                $credential,
                (string) ($request->header('Origin') ?? ''),
                (string) $request->input('name', '')
            );
        } catch (\Throwable $e) {
            report($e);
            return $this->fail([400, __('Passkey verification failed')]);
        }
        return $this->success(['id' => $passkey->id, 'name' => $passkey->name]);
    }

    public function list(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        return $this->success(
            \App\Models\UserPasskey::where('user_id', $user->id)->orderByDesc('id')->get()->map(fn (\App\Models\UserPasskey $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'created_at' => $p->created_at,
                'last_used_at' => $p->last_used_at,
                'backup_eligible' => $p->backup_eligible,
            ])->all()
        );
    }

    public function delete(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $id = (int) $request->input('id');
        $passkey = $user->passkeys()->where('id', $id)->first();
        if (!$passkey) {
            return $this->fail([400, __('Passkey not found')]);
        }
        if ($user->passkeys()->count() <= 1) {
            return $this->fail([400, __('At least one passkey must be kept')]);
        }
        $passkey->delete();
        return $this->success(true);
    }

    public function status(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        return $this->success([
            'has_passkey' => $this->passkeyService->hasPasskey($user),
            'count' => $user->passkeys()->count(),
        ]);
    }

    private function resolveTicketUser(string $ticket): ?User
    {
        $userId = \Illuminate\Support\Facades\Cache::get(
            \App\Utils\CacheKey::get('PASSKEY_LOGIN_TICKET', $ticket)
        );
        if (!$userId) {
            return null;
        }
        $user = User::find($userId);
        if (!$user || $user->banned || !$user->is_admin) {
            return null;
        }
        return $user;
    }
}
