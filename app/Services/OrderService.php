<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\PlanService;

class OrderService
{
    /**
     * 周期 → 相对月数（用于折抵估算；时/天为分数月）
     * 实际到期时间见 getTime()，时/天走 addHours/addDays。
     */
    const STR_TO_TIME = [
        Plan::PERIOD_HOURLY => 1 / 720,
        Plan::PERIOD_DAILY => 1 / 30,
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36,
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Create an order from a request.
     *
     * @param User $user
     * @param Plan $plan
     * @param string $period
     * @param string|null $couponCode
     * @return Order
     * @throws ApiException
     */
    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService) {
            // 锁用户行后再查未完成订单，避免并发 save 穿过控制器检查产生多笔 PENDING
            $lockedUser = User::query()->lockForUpdate()->find($user->id);
            if (!$lockedUser) {
                throw new ApiException(__('The user does not exist'));
            }
            $user = $lockedUser;

            if ($userService->isNotCompleteOrderByUserId($user->id)) {
                throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
            }

            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => Helper::yuanToCents(optional($plan->prices)[$newPeriod] ?? 0),
                // 必须显式写入：仅靠 DB default 时，save 后内存模型 status 仍为 null，
                // 后续若直接对返回实例调 paid() 会因 status !== PENDING 被当成已处理而跳过入账。
                'status' => Order::STATUS_PENDING,
            ]);

            $orderService = new self($order);

            if ($couponCode) {
                $orderService->applyCoupon($couponCode);
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);

            // 余额抵扣会改写 total_amount / balance_amount，佣金必须按抵扣后实际应付金额计算，
            // 避免全额使用余额（实付 0）仍按原价返利导致余额重复计佣。
            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            $orderService->setInvite(user: $user);

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            // 兼容旧钩子
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public function open(): void
    {
        $order = $this->order;

        HookManager::call('order.open.before', $order);

        $opened = false;
        DB::transaction(function () use ($order, &$opened) {
            // 锁订单行并抢占 PROCESSING：Job 重试 / 并发 open 不得二次加 surplus_credit / 延订阅
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException('订单不存在');
            }
            $this->order = $locked;
            $order = $locked;

            if ((int) $order->status === Order::STATUS_COMPLETED) {
                return; // 已开通，幂等退出
            }
            if ((int) $order->status !== Order::STATUS_PROCESSING) {
                throw new \RuntimeException('订单状态不允许开通: ' . $order->status);
            }

            $this->user = User::lockForUpdate()->find($order->user_id);
            if (!$this->user) {
                throw new \RuntimeException('用户不存在');
            }

            // 以锁后行数据判定，避免锁前快照与落库不一致
            if (self::isDepositOrder($order)) {
                // surplus_amount 在创建时冻结赠送金额；缺失时回退现算（兼容历史单）
                $bonus = $order->surplus_amount !== null
                    ? max(0, (int) $order->surplus_amount)
                    : self::calculateDepositBonus((int) $order->total_amount);
                $credit = max(0, (int) $order->total_amount) + $bonus;
                if ($credit <= 0) {
                    throw new \RuntimeException('充值金额无效');
                }
                $this->user->balance = (int) $this->user->balance + $credit;
            } else {
                $plan = Plan::find($order->plan_id);
                if (!$plan) {
                    throw new \RuntimeException('订阅计划不存在');
                }

                if ($order->surplus_credit) {
                    $this->user->balance += $order->surplus_credit;
                }

                if ($order->surplus_order_ids) {
                    Order::whereIn('id', $order->surplus_order_ids)
                        ->where('period', '!=', Order::PERIOD_DEPOSIT)
                        ->where('type', '!=', Order::TYPE_DEPOSIT)
                        ->update(['status' => Order::STATUS_DISCOUNTED]);
                }

                if (UserPlan::isEnabled()) {
                    $this->openMultiPlan($order, $plan);
                } else {
                    match ((string) $order->period) {
                        Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                        Plan::PERIOD_RESET_TRAFFIC => app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER),
                        default => $this->buyByPeriod($order, $plan),
                    };

                    $this->setSpeedLimit($plan->speed_limit);
                    $this->setDeviceLimit($plan->device_limit);
                }
            }

            if (!$this->user->save()) {
                throw new \RuntimeException('用户信息保存失败');
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('订单信息保存失败');
            }
            $opened = true;
        });

        if (!$opened) {
            // 幂等：已是 COMPLETED 时不重复触发事件/钩子副作用
            return;
        }

        $order = $this->order;

        // 必须按订单 type 匹配（新购/续费/升级），勿误用 STATUS_* 常量。
        // 历史上曾写成 STATUS_PROCESSING，虽与 TYPE_NEW_PURCHASE 同为 1 碰巧生效，语义错误。
        $eventId = match ((int) $order->type) {
            Order::TYPE_NEW_PURCHASE => admin_setting('new_order_event_id', 0),
            Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
            Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
            default => 0,
        };

        if ($eventId) {
            $this->openEvent($eventId);
        }

        HookManager::call('order.open.after', $order);
    }

    public static function isDepositOrder(Order $order): bool
    {
        return (string) $order->period === Order::PERIOD_DEPOSIT
            || (int) $order->type === Order::TYPE_DEPOSIT;
    }

    /**
     * 按阶梯计算充值赠送金额（单位：分）
     * 配置 deposit_bonus：数组项为 "门槛元:赠送元"，取满足门槛的最大赠送
     */
    public static function calculateDepositBonus(int $totalAmountCents): int
    {
        if ($totalAmountCents <= 0) {
            return 0;
        }

        $tiers = admin_setting('deposit_bonus', []);
        if (!is_array($tiers) || empty($tiers)) {
            $tiers = admin_setting('deposit_bounus', []);
        }
        if (!is_array($tiers) || empty($tiers)) {
            return 0;
        }

        $bonus = 0;
        foreach ($tiers as $tier) {
            if (!is_string($tier) || !str_contains($tier, ':')) {
                continue;
            }
            [$thresholdYuan, $bonusYuan] = array_pad(explode(':', $tier, 2), 2, 0);
            if (!is_numeric($thresholdYuan) || !is_numeric($bonusYuan)) {
                continue;
            }
            $thresholdCents = (int) round((float) $thresholdYuan * 100);
            $bonusCents = (int) round((float) $bonusYuan * 100);
            if ($thresholdCents < 0 || $bonusCents < 0) {
                continue;
            }
            if ($totalAmountCents >= $thresholdCents) {
                $bonus = max($bonus, $bonusCents);
            }
        }

        return $bonus;
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
            return;
        }
        if (UserPlan::isEnabled()) {
            $this->setOrderTypeMulti($user);
            return;
        }
        if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException('目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = Order::TYPE_UPGRADE;
            if ((int) admin_setting('surplus_enable', 1))
                $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->surplus_credit = (int) ($order->surplus_amount - $order->total_amount);
                $order->total_amount = 0;
            } else {
                $order->total_amount = (int) ($order->total_amount - $order->surplus_amount);
            }
        } else if (($user->expired_at === null || $user->expired_at > time()) && $order->plan_id == $user->plan_id) { // 用户订阅未过期或按流量订阅 且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    /**
     * 多套餐下单类型判定（按实例，不读主表快照）。
     * - onetime 永远新购；同 plan 持有 cycle 行（不管过没过期）即续费，否则一律新购。
     * - 升级分支不可达：换套餐直接按新购下单；getSurplusValue 保持单套餐专用。
     */
    private function setOrderTypeMulti(User $user): void
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_ONETIME) {
            $order->type = Order::TYPE_NEW_PURCHASE;
            return;
        }
        $hasCycleRow = UserPlan::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $order->plan_id)
            ->where('kind', UserPlan::KIND_CYCLE)
            ->exists();
        $order->type = $hasCycleRow ? Order::TYPE_RENEWAL : Order::TYPE_NEW_PURCHASE;
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        $originalTotal = (int) $order->total_amount;
        $existingDiscount = (int) $order->discount_amount;

        // 优惠券已写入 discount_amount，但尚未扣减 total。
        // VIP 折扣必须按「扣券后的剩余应付」计算，避免券+VIP 叠加超过原价导致 total 为负。
        $baseAfterCoupon = $originalTotal - $existingDiscount;
        if ($baseAfterCoupon < 0) {
            $order->discount_amount = $originalTotal;
            $order->total_amount = 0;
            return;
        }

        $vipExtra = 0;
        if ($user->discount) {
            $vipExtra = Helper::percentOfCents($baseAfterCoupon, $user->discount);
        }

        $order->discount_amount = $existingDiscount + $vipExtra;
        if ($order->discount_amount > $originalTotal) {
            $order->discount_amount = $originalTotal;
        }
        $order->total_amount = max(0, $originalTotal - (int) $order->discount_amount);
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true) ? User::COMMISSION_TYPE_ONETIME : User::COMMISSION_TYPE_PERIOD;
        }
        $isCommission = false;
        switch ($commissionType) {
            case User::COMMISSION_TYPE_PERIOD:
                $isCommission = true;
                break;
            case User::COMMISSION_TYPE_ONETIME:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        // total_amount 此时应为余额/优惠抵扣后的实际应付金额（整数分）
        // 必须用整数运算：699 * 20% = 139.8，float 写入 MySQL INTEGER 在严格模式下会失败
        $rate = $inviter->commission_rate
            ? $inviter->commission_rate
            : admin_setting('invite_commission', 10);
        $order->commission_balance = Helper::percentOfCents((int) $order->total_amount, $rate);
    }

    private function haveValidOrder(User $user): Order|null
    {
        // 余额充值不算「有效套餐订单」，避免阻断首单返佣
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->where('period', '!=', Order::PERIOD_DEPOSIT)
            ->where('type', '!=', Order::TYPE_DEPOSIT)
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $lastOneTimeOrder = Order::where('user_id', $user->id)
                ->where('period', Plan::PERIOD_ONETIME)
                ->where('status', Order::STATUS_COMPLETED)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$lastOneTimeOrder)
                return;
            $nowUserTraffic = Helper::transferToGB($user->transfer_enable);
            if (!$nowUserTraffic)
                return;
            $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
            if (!$paidTotalAmount)
                return;
            $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
            $notUsedTraffic = $nowUserTraffic - Helper::transferToGB($user->u + $user->d);
            $result = $trafficUnitPrice * $notUsedTraffic;
            $order->surplus_amount = (int) ($result > 0 ? $result : 0);
            $order->surplus_order_ids = Order::where('user_id', $user->id)
                ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Order::PERIOD_DEPOSIT])
                ->where('type', '!=', Order::TYPE_DEPOSIT)
                ->where('status', Order::STATUS_COMPLETED)
                ->pluck('id')
                ->all();
        } else {
            $orders = Order::query()
                ->where('user_id', $user->id)
                ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME, Order::PERIOD_DEPOSIT])
                ->where('type', '!=', Order::TYPE_DEPOSIT)
                ->where('status', Order::STATUS_COMPLETED)
                ->get();

            if ($orders->isEmpty()) {
                $order->surplus_amount = 0;
                $order->surplus_order_ids = [];
                return;
            }

            // 只计用户实付（总价+余额抵扣），不计历史折抵字段，避免 surplus 互相叠加放大。
            $orderAmountSum = $orders->sum(
                fn($item) => (int) $item->total_amount + (int) $item->balance_amount
            );
            $orderMonthSum = $orders->sum(fn($item) => self::STR_TO_TIME[PlanService::getPeriodKey($item->period)] ?? 0);
            $firstOrderAt = (int) $orders->min('created_at');

            // 以用户当前 expired_at 为权益终点（比 first+addMonths 更贴近真实到期，避免日历月误差倒贴余额）
            $expiredAtTs = $user->expired_at !== null
                ? (int) $user->expired_at
                : Carbon::createFromTimestamp($firstOrderAt)->addMonths($orderMonthSum)->timestamp;

            $nowTs = time();
            $totalSeconds = max(0, $expiredAtTs - $firstOrderAt);
            $remainSeconds = max(0, $expiredAtTs - $nowTs);
            $cycleRatio = $totalSeconds > 0 ? $remainSeconds / $totalSeconds : 0;

            $plan = Plan::find($user->plan_id);
            $totalTraffic = $plan?->transfer_enable * $orderMonthSum;
            $usedTraffic = Helper::transferToGB($user->u + $user->d);
            $remainTraffic = max(0, $totalTraffic - $usedTraffic);
            $trafficRatio = $totalTraffic > 0 ? $remainTraffic / $totalTraffic : 0;

            $ratio = $cycleRatio;
            if (admin_setting('change_order_event_id', 0) == 1) {
                $ratio = min($cycleRatio, $trafficRatio);
            }

            $surplus = (int) max(0, $orderAmountSum * $ratio);
            // 折抵不得超过本单原价：同价/更贵升级不产生 surplus_credit 余额倒贴；
            // 降级时仍可在 setOrderType 中用 (surplus - total) 形成 credit。
            // 这里不截断到 total（尚未知本单最终价），但禁止因日历误差使 ratio 推高到 > 实付总额。
            $surplus = min($surplus, (int) $orderAmountSum);

            $order->surplus_amount = $surplus;
            $order->surplus_order_ids = $orders->pluck('id')->all();
        }
    }

    public function paid(string $callbackNo)
    {
        // 行锁 + 事务内二次确认 status，避免并发回调/手动入账双开订阅。
        // open/Job 放在事务外，避免与 OrderHandleJob 的 lockForUpdate 嵌套死锁。
        try {
            $claimed = DB::transaction(function () use ($callbackNo) {
                $order = Order::query()
                    ->whereKey($this->order->id)
                    ->lockForUpdate()
                    ->first();
                if (!$order) {
                    return false;
                }
                $this->order = $order;

                if ((int) $order->status !== Order::STATUS_PENDING) {
                    return 'already';
                }

                HookManager::call('order.paid.before', $order);

                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                if (!$order->save()) {
                    return false;
                }

                return true;
            });
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }

        if ($claimed === 'already') {
            return true;
        }
        if ($claimed !== true) {
            return false;
        }

        try {
            OrderHandleJob::dispatchSync($this->order->trade_no);
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }

        HookManager::call('order.paid.after', $this->order);
        return true;
    }

    public function cancel(): bool
    {
        $order = $this->order;
        // 仅待支付可取消；避免重复 cancel 把 limit_use / 余额回滚多次
        if ((int) $order->status !== Order::STATUS_PENDING) {
            return (int) $order->status === Order::STATUS_CANCELLED;
        }

        HookManager::call('order.cancel.before', $order);
        try {
            return DB::transaction(function () {
                // 行锁 + 二次 status：paid 已抢 PROCESSING 后，旧模型 cancel 不得覆盖并退余额
                $order = Order::query()->whereKey($this->order->id)->lockForUpdate()->first();
                if (!$order) {
                    return false;
                }
                $this->order = $order;

                if ((int) $order->status !== Order::STATUS_PENDING) {
                    return (int) $order->status === Order::STATUS_CANCELLED;
                }

                $order->status = Order::STATUS_CANCELLED;
                if (!$order->save()) {
                    throw new \Exception('Failed to save order status.');
                }
                if ($order->balance_amount) {
                    $userService = new UserService();
                    if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                        throw new \Exception('Failed to add balance.');
                    }
                }
                // 下单时 CouponService::use 已扣减 limit_use；取消待支付订单须回滚，
                // 否则限量券会被空耗（用户未实际完成支付）。
                if ($order->coupon_id) {
                    $coupon = Coupon::query()->lockForUpdate()->find($order->coupon_id);
                    if ($coupon && $coupon->limit_use !== null) {
                        $coupon->limit_use = (int) $coupon->limit_use + 1;
                        if (!$coupon->save()) {
                            throw new \Exception('Failed to restore coupon limit_use.');
                        }
                    }
                }
                HookManager::call('order.cancel.after', $order);
                return true;
            });
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($this->user->expired_at === NULL || $order->type === Order::TYPE_NEW_PURCHASE)
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Plan $plan)
    {
        app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * 多套餐开通：单写实例表，主表快照列一律不动。
     * 调用时用户行已加锁；此处再锁该用户全部实例行（三铁律③），cycle 行复用不断行。
     */
    private function openMultiPlan(Order $order, Plan $plan): void
    {
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            // 多套餐下升级折抵禁用：升级分支不可达，收到升级单直接失败，提示按新购下单。
            throw new \RuntimeException('多套餐模式下不支持升级折抵，请按新购下单');
        }

        // 先锁用户全部实例行，再做找行/建行业务。
        UserPlan::query()->where('user_id', $this->user->id)->lockForUpdate()->get();

        match ((string) $order->period) {
            Plan::PERIOD_ONETIME => $this->openPack($order, $plan),
            Plan::PERIOD_RESET_TRAFFIC => $this->openResetPackage($order, $plan),
            default => $this->openCycle($order, $plan),
        };
    }

    /**
     * 周期套餐：按 (user, plan, kind=1) 找最新行，不管过没过期。
     * - 有效期内购买=续费：配额累加、到期顺延、用量保留；
     * - 已过期后购买=新周期：复用同一行，配额覆盖、u/d 清零、到期从 now 起算。
     * 两者都追加 order_ids，不断史。禁止过期建新行，禁止盲建。
     */
    private function openCycle(Order $order, Plan $plan): void
    {
        $row = UserPlan::query()
            ->where('user_id', $this->user->id)
            ->where('plan_id', $plan->id)
            ->where('kind', UserPlan::KIND_CYCLE)
            ->orderBy('id', 'desc')
            ->first();

        $quota = (int) $plan->transfer_enable * 1073741824;
        $now = time();

        if (!$row) {
            $row = new UserPlan();
            $row->forceFill([
                'user_id' => $this->user->id,
                'plan_id' => $plan->id,
                'kind' => UserPlan::KIND_CYCLE,
                'group_id' => $plan->group_id,
                'order_ids' => [],
                'u' => 0,
                'd' => 0,
                'sort_order' => 0,
            ]);
            $row->transfer_enable = $quota;
            $row->expired_at = $this->getTime((string) $order->period, $now);
        } elseif ($row->isActive($now)) {
            // 续费：累加配额、顺延到期（基准用实例自身的 expired_at）、用量保留
            $row->transfer_enable = (int) $row->transfer_enable + $quota;
            $row->expired_at = $this->getTime((string) $order->period, (int) $row->expired_at);
        } else {
            // 新周期：覆盖配额、清零用量、到期从 now 起算（防旧剩余额度白送）
            $row->transfer_enable = $quota;
            $row->u = 0;
            $row->d = 0;
            $row->exhausted_at = null;
            $row->expired_at = $this->getTime((string) $order->period, $now);
        }

        $row->group_id = $plan->group_id;
        $row->speed_limit = $plan->speed_limit;
        $row->device_limit = $plan->device_limit;
        $row->next_reset_at = $this->nextResetForPlan($plan, $row->expired_at);
        $row->appendOrderId((int) $order->id);
        $row->save();
    }

    /**
     * 流量包：永远新建 pack 行；同事务退役同 plan 已耗尽的 pack 行
     * （u+d >= 配额的行 expired_at=now，行保留备查；有剩余额度的包行不动）。
     */
    private function openPack(Order $order, Plan $plan): void
    {
        $row = new UserPlan();
        $row->forceFill([
            'user_id' => $this->user->id,
            'plan_id' => $plan->id,
            'kind' => UserPlan::KIND_PACK,
            'group_id' => $plan->group_id,
            'order_ids' => [(int) $order->id],
            'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => null,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
            'sort_order' => 0,
        ]);
        $row->next_reset_at = $this->nextResetForPlan($plan, $row->expired_at);
        $row->save();

        $now = time();
        $retired = UserPlan::query()
            ->where('user_id', $this->user->id)
            ->where('plan_id', $plan->id)
            ->where('kind', UserPlan::KIND_PACK)
            ->where('id', '!=', $row->id)
            ->whereRaw('u + d >= transfer_enable')
            ->get();
        foreach ($retired as $old) {
            $old->expired_at = $now;
            if ($old->exhausted_at === null) {
                $old->exhausted_at = $now;
            }
            $old->save();
        }
    }

    /**
     * 重置包：只重置订单 plan 对应的 cycle 行（配额/到期不动，
     * next_reset_at 按规则重算），追加订单 id；pack 行不参与。
     * 清零走统一 resetInstance（force=true），与 cron/手动同一函数。
     */
    private function openResetPackage(Order $order, Plan $plan): void
    {
        $row = UserPlan::query()
            ->where('user_id', $this->user->id)
            ->where('plan_id', $plan->id)
            ->where('kind', UserPlan::KIND_CYCLE)
            ->orderBy('id', 'desc')
            ->first();

        if (!$row) {
            throw new \RuntimeException('该套餐没有可重置的周期实例');
        }

        if (!app(TrafficResetService::class)->resetInstance($row, TrafficResetLog::SOURCE_ORDER, true)) {
            throw new \RuntimeException('周期实例重置失败');
        }

        $row = UserPlan::query()->whereKey($row->id)->first();
        $row->appendOrderId((int) $order->id);
        $row->save();
    }

    /**
     * 实例 next_reset_at：锚定实例自身的 expired_at（各行独立周期）。
     */
    private function nextResetForPlan(Plan $plan, ?int $expiredAt): ?int
    {
        $next = app(TrafficResetService::class)->calculateNextResetTimeForPlan($plan, $expiredAt);

        return $next?->timestamp;
    }

    /**
     * 计算套餐到期时间
     * @param string $periodKey
     * @param int $timestamp
     * @return int
     * @throws ApiException
     */
    private function getTime(string $periodKey, ?int $timestamp = null): int
    {
        $timestamp = $timestamp < time() ? time() : $timestamp;
        $periodKey = PlanService::getPeriodKey($periodKey);
        $base = Carbon::createFromTimestamp($timestamp);

        // 时/天用精确加减，避免分数月带来的日历误差
        return match ($periodKey) {
            Plan::PERIOD_HOURLY => $base->addHours(1)->timestamp,
            Plan::PERIOD_DAILY => $base->addDays(1)->timestamp,
            Plan::PERIOD_MONTHLY,
            Plan::PERIOD_QUARTERLY,
            Plan::PERIOD_HALF_YEARLY,
            Plan::PERIOD_YEARLY,
            Plan::PERIOD_TWO_YEARLY,
            Plan::PERIOD_THREE_YEARLY => $base->addMonths((int) self::STR_TO_TIME[$periodKey])->timestamp,
            default => throw new ApiException('无效的套餐周期'),
        };
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                // 多套餐下主表 u/d 已冻结，实例用量由各自开通/重置路径维护，此处不再清主表。
                if (UserPlan::isEnabled()) {
                    break;
                }
                app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
                break;
        }
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    /**
     * Summary of handleUserBalance
     * @param User $user
     * @param UserService $userService
     * @return void
     */
    protected function handleUserBalance(User $user, UserService $userService): void
    {
        // 事务内重新锁行读取余额，避免并发下单用内存中的旧 balance 重复抵扣。
        $fresh = User::query()->lockForUpdate()->find($this->order->user_id);
        if (!$fresh) {
            throw new ApiException(__('The user does not exist'));
        }
        $available = (int) $fresh->balance;
        $payable = (int) $this->order->total_amount;

        if ($available <= 0) {
            return;
        }

        if ($available >= $payable) {
            if (!$userService->addBalance($this->order->user_id, -$payable)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $payable;
            $this->order->total_amount = 0;
        } else {
            if (!$userService->addBalance($this->order->user_id, -$available)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $available;
            $this->order->total_amount = $payable - $available;
        }
        // 同步调用方持有的 user 模型，避免后续逻辑读到旧余额
        $user->balance = $available - (int) $this->order->balance_amount;
    }
}
