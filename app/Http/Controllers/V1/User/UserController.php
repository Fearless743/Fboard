<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    protected $loginService;

    public function __construct(
        LoginService $loginService
    ) {
        $this->loginService = $loginService;
    }

    public function getActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->getSessions());
    }

    public function removeActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user()?->id ? true : false
        ];
        if ($request->user()?->is_admin) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = $request->user();
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )
        ) {
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }

        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        } else {
            $user->tokens()->delete();
        }

        HookManager::call('user.change_password.after', [
            'user' => $user,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        if (\App\Services\UserPlanService::multiPlanEnabled()) {
            $user['plans'] = $this->buildPlansPayload($request->user()->id);
        }
        $user = HookManager::filter('user.info.response', $user, $request);
        return $this->success($user);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            User::where('invite_user_id', $request->user()->id)
                ->count()
        ];
        return $this->success($stat);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        if (\App\Services\UserPlanService::multiPlanEnabled()) {
            $user['plans'] = $this->buildPlansPayload($request->user()->id);
        }
        $user = HookManager::filter('user.subscribe.response', $user);
        return $this->success($user);
    }

    /**
     * 多套餐模式：构建用户套餐实例明细（供前台展示）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPlansPayload(int $userId): array
    {
        return \App\Services\UserPlanService::getActiveInstances($userId)
            ->map(function ($inst) {
                return [
                    'id' => $inst->id,
                    'plan_id' => $inst->plan_id,
                    'plan_name' => $inst->plan?->name,
                    'transfer_enable' => (int) $inst->transfer_enable,
                    'u' => (int) $inst->u,
                    'd' => (int) $inst->d,
                    'expired_at' => $inst->expired_at,
                    'next_reset_at' => $inst->next_reset_at,
                ];
            })
            ->values()
            ->all();
    }

    public function resetSecurity(Request $request)
    {
        $user = $request->user();
        $oldUuid = $user->uuid;
        $oldToken = $user->token;
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }

        HookManager::call('user.reset_security.after', [
            'user' => $user,
            'old_uuid' => $oldUuid,
            'old_token' => $oldToken,
            'request' => $request,
        ]);

        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic'
        ]);

        $user = $request->user();

        HookManager::call('user.update.before', [
            'user' => $user,
            'params' => $updateData,
            'request' => $request,
        ]);

        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, __('Save failed')]);
        }

        HookManager::call('user.update.after', [
            'user' => $user,
            'params' => $updateData,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function transfer(UserTransfer $request)
    {
        $amount = $request->input('transfer_amount');
        try {
            DB::transaction(function () use ($request, $amount) {
                $user = User::lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new \Exception(__('The user does not exist'));
                }
                if ($amount > $user->commission_balance) {
                    throw new \Exception(__('Insufficient commission balance'));
                }
                $user->commission_balance -= $amount;
                $user->balance += $amount;
                if (!$user->save()) {
                    throw new \Exception(__('Transfer failed'));
                }

                HookManager::call('user.transfer.after', [
                    'user' => $user,
                    'amount' => $amount,
                    'request' => $request,
                ]);
            });
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage()]);
        }
        return $this->success(true);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = $request->user();

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }
}
