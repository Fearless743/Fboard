<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BalanceDepositController extends Controller
{
    public function create(Request $request)
    {
        if (!(bool) admin_setting('deposit_enable', 1)) {
            return $this->fail([400, __('Balance deposit is not enabled')]);
        }

        $rawAmount = $request->input('deposit_amount');
        if ($rawAmount === null || $rawAmount === '' || !is_numeric($rawAmount)) {
            return $this->fail([422, __('Invalid deposit amount')]);
        }
        // 仅接受整数分，拒绝 10.5 等被 (int) 截断的值
        if (floor((float) $rawAmount) != (float) $rawAmount) {
            return $this->fail([422, __('Deposit amount must be an integer in cents')]);
        }

        $amount = (int) $rawAmount;
        if ($amount <= 0) {
            return $this->fail([422, __('Invalid deposit amount')]);
        }
        $minAmount = max(1, (int) admin_setting('deposit_min_amount', 100));
        $maxAmount = max($minAmount, (int) admin_setting('deposit_max_amount', 999999900));

        if ($amount < $minAmount) {
            return $this->fail([400, __('Deposit amount must be at least :amount cents', ['amount' => $minAmount])]);
        }
        if ($amount > $maxAmount) {
            return $this->fail([400, __('Deposit amount is too large, please contact the administrator')]);
        }

        $user = $request->user();
        if (!$user) {
            return $this->fail([401, __('User not logged in')]);
        }

        $userService = app(UserService::class);
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->fail([400, __('You have an unpaid or pending order, please try again later or cancel it')]);
        }

        try {
            $tradeNo = DB::transaction(function () use ($user, $amount) {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);
                if (!$lockedUser) {
                    throw new \RuntimeException(__('The user does not exist'));
                }

                $userService = app(UserService::class);
                if ($userService->isNotCompleteOrderByUserId($lockedUser->id)) {
                    throw new \RuntimeException(__('You have an unpaid or pending order, please try again later or cancel it'));
                }

                $bonus = OrderService::calculateDepositBonus($amount);

                $order = new Order([
                    'user_id' => $lockedUser->id,
                    'plan_id' => 0,
                    'period' => Order::PERIOD_DEPOSIT,
                    'trade_no' => Helper::generateOrderNo(),
                    'total_amount' => $amount,
                    // 充值单复用 surplus_amount 冻结创建时的赠送金额，避免支付后改配置导致到账不一致
                    'surplus_amount' => $bonus,
                    'type' => Order::TYPE_DEPOSIT,
                    'status' => Order::STATUS_PENDING,
                    'balance_amount' => 0,
                    'discount_amount' => 0,
                    'surplus_credit' => 0,
                    'coupon_id' => null,
                ]);

                $orderService = new OrderService($order);
                if ((bool) admin_setting('deposit_commission_enable', 1)) {
                    $orderService->setInvite(user: $lockedUser);
                }

                if (!$order->save()) {
                    throw new \RuntimeException(__('Failed to create order'));
                }

                return $order->trade_no;
            });
        } catch (\Throwable $e) {
            return $this->fail([400, $e->getMessage() ?: __('Failed to create order')]);
        }

        return $this->success([
            'data' => $tradeNo,
        ]);
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);

        $order = Order::with(['payment'])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();

        if (!$order || !OrderService::isDepositOrder($order)) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }

        // 已创建订单允许查看/继续支付，不受后续关闭充值开关影响
        $bonus = $order->surplus_amount !== null
            ? max(0, (int) $order->surplus_amount)
            : OrderService::calculateDepositBonus((int) $order->total_amount);
        $payload = $order->toArray();
        $payload['plan'] = [
            'id' => 0,
            'name' => 'deposit',
        ];
        $payload['period'] = Order::PERIOD_DEPOSIT;
        $payload['bounus'] = $bonus;
        $payload['bonus'] = $bonus;
        $payload['get_amount'] = (int) $order->total_amount + $bonus;

        return $this->success($payload);
    }
}
