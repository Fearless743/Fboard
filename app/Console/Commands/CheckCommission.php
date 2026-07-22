<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if (!$this->isCommissionAutoCheckEnabled()) {
            return;
        }

        // 计时锚点：优先 paid_at（支付/完成时写入），避免管理端改单/触碰订单刷新 updated_at 后永远等不满 3 天。
        // paid_at 为空时回退 updated_at / created_at（历史单兼容）。
        $deadline = strtotime('-3 day', time());

        Order::query()
            ->where(function ($q) {
                // 0=待确认；历史库中可能仍有 NULL
                $q->where('commission_status', 0)
                    ->orWhereNull('commission_status');
            })
            ->whereNotNull('invite_user_id')
            ->where('status', Order::STATUS_COMPLETED)
            ->where(function ($q) use ($deadline) {
                $q->where(function ($q2) use ($deadline) {
                    $q2->whereNotNull('paid_at')
                        ->where('paid_at', '<=', $deadline);
                })->orWhere(function ($q2) use ($deadline) {
                    $q2->whereNull('paid_at')
                        ->where('updated_at', '<=', $deadline);
                });
            })
            ->update([
                'commission_status' => 1,
            ]);
    }

    /**
     * 设置可能是 1/0、true/false、"1"/"0"、JSON 解码后的 bool；禁止 (int)"true"===0 误关。
     */
    private function isCommissionAutoCheckEnabled(): bool
    {
        $raw = admin_setting('commission_auto_check_enable', 1);
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            $filtered = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }
            return $raw === '1';
        }

        return (bool) $raw;
    }

    public function autoPayCommission()
    {
        $orderIds = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->pluck('id');
        foreach ($orderIds as $orderId) {
            try {
                DB::transaction(function () use ($orderId) {
                    // 行锁 + 状态抢占：同一订单并发两次不得双发
                    $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
                    if (!$order || (int) $order->commission_status !== 1 || !$order->invite_user_id) {
                        return;
                    }
                    if (!$this->payHandle($order->invite_user_id, $order)) {
                        throw new \RuntimeException('payHandle failed for order ' . $orderId);
                    }
                    $order->commission_status = 2;
                    if (!$order->save()) {
                        throw new \RuntimeException('failed to mark commission paid for order ' . $orderId);
                    }
                });
            } catch (\Exception $e) {
                // 单笔失败不中断整批；记录后继续
                $this->error($e->getMessage());
            }
        }
    }

    public function payHandle($inviteUserId, Order $order)
    {
        // 已发放或非待发放：幂等拒绝（测试/重入/漏锁场景）
        if ((int) $order->commission_status === 2) {
            return false;
        }
        // 同一 trade_no 已有日志则视为已发过，防 status 未推进时的重入
        if (CommissionLog::where('trade_no', $order->trade_no)->exists()) {
            return false;
        }

        $level = 3;
        if ((int) admin_setting('commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int) admin_setting('commission_distribution_l1'),
                1 => (int) admin_setting('commission_distribution_l2'),
                2 => (int) admin_setting('commission_distribution_l3'),
            ];
        } else {
            $commissionShareLevels = [
                0 => 100,
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::query()->lockForUpdate()->find($inviteUserId);
            if (!$inviter) {
                continue;
            }
            if (!isset($commissionShareLevels[$l])) {
                continue;
            }
            $commissionBalance = Helper::percentOfCents(
                (int) $order->commission_balance,
                $commissionShareLevels[$l]
            );
            if (!$commissionBalance) {
                continue;
            }
            if ((int) admin_setting('withdraw_close_enable', 0)) {
                $inviter->increment('balance', $commissionBalance);
            } else {
                $inviter->increment('commission_balance', $commissionBalance);
            }
            CommissionLog::create([
                'invite_user_id' => $inviteUserId,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance,
            ]);
            $inviteUserId = $inviter->invite_user_id;
            $order->actual_commission_balance = (int) $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}
