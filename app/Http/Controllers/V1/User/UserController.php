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
use App\Models\UserPlan;
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
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'balance',
                'commission_balance',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $data = $user->toArray();
        $data['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        $model = User::find($request->user()->id);
        if ($model) {
            $model->loadMissing('userPlans');
            $data = array_merge($data, $model->getComputedPlanFields());
            $data['plan_list'] = $model->getPlanList();
        }
        $data = HookManager::filter('user.info.response', $data, $request);
        return $this->success($data);
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
            ->select(['token', 'email', 'uuid'])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $data = $user->toArray();
        $model = User::find($request->user()->id);
        if ($model) {
            $model->loadMissing('userPlans');
            $computed = $model->getComputedPlanFields();
            $data = array_merge($data, $computed);
            $data['plan_list'] = $model->getPlanList();
            // 主表已无 plan_id：plan 对象按单实例直出，多实例时置空由 plan_list 承载
            if (!empty($computed['plan_id'])) {
                $data['plan'] = Plan::find($computed['plan_id']);
                if (!$data['plan']) {
                    return $this->fail([400, __('Subscription plan does not exist')]);
                }
            }
        }
        $data['subscribe_url'] = Helper::getSubscribeUrl($data['token']);
        $userService = new UserService();
        $data['reset_day'] = $userService->getResetDay($user);
        $data = HookManager::filter('user.subscribe.response', $data);
        return $this->success($data);
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

    /**
     * 多套餐消耗顺序：收全量有序实例 id 数组，按下标赋 sort_order=1..n。
     * 未列出的行保持原值（默认 0 沉底）。续费/新购不碰顺序。
     */
    public function planSort(Request $request)
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            return $this->fail([400, __('参数错误')]);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $user = $request->user();
        if (!empty($ids)) {
            // 逐个校验归属当前用户：出现他人实例 id 即 403。
            $owned = UserPlan::where('user_id', $user->id)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            sort($owned);
            $sorted = $ids;
            sort($sorted);
            if ($owned !== $sorted) {
                abort(403, '无权操作该套餐实例');
            }
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $id) {
                UserPlan::whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return $this->success(true);
    }
}
