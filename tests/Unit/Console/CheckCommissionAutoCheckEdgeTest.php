<?php

namespace Tests\Unit\Console;

use App\Console\Commands\CheckCommission;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 复现「满 3 天仍不自动确认」的边界：
 * - commission_status 为 NULL（历史/漏写）应被当作待确认
 * - 应以 paid_at（支付完成时间）而非易被刷新的 updated_at 计时
 * - 开关存 "true" 字符串时 (int) 会变成 0 导致整段跳过
 */
class CheckCommissionAutoCheckEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_commission_status_should_be_auto_checked(): void
    {
        admin_setting(['commission_auto_check_enable' => 1]);

        $inviter = $this->user('e-inv@example.com');
        $buyer = $this->user('e-buy@example.com', $inviter->id);

        $oldTs = strtotime('-4 day');
        // sqlite 测试库 commission_status NOT NULL：用 raw 插入模拟 MySQL 历史 NULL 行
        $id = DB::table('v2_order')->insertGetId([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000,
            'invite_user_id' => $inviter->id,
            'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
            'commission_status' => 0,
            'paid_at' => $oldTs,
            'created_at' => $oldTs,
            'updated_at' => $oldTs,
        ]);
        // 尝试置 NULL（MySQL 历史）；sqlite 若拒则跳过本用例的 NULL 分支
        try {
            DB::statement('UPDATE v2_order SET commission_status = NULL WHERE id = ?', [$id]);
        } catch (\Throwable) {
            $this->markTestSkipped('当前库不允许 commission_status=NULL');
        }

        app(CheckCommission::class)->autoCheck();
        $status = DB::table('v2_order')->where('id', $id)->value('commission_status');

        $this->assertSame(
            1,
            (int) $status,
            'commission_status=NULL 的满 3 天完成单应被自动确认，当前仍为: ' . var_export($status, true)
        );
    }

    public function test_should_use_paid_at_not_fresh_updated_at(): void
    {
        admin_setting(['commission_auto_check_enable' => 1]);

        $inviter = $this->user('e2-inv@example.com');
        $buyer = $this->user('e2-buy@example.com', $inviter->id);

        // 4 天前支付完成，但 updated_at 被刷新为现在（管理端改备注/触碰订单等）
        $paidAt = strtotime('-4 day');
        $order = $this->order($buyer, $inviter, [
            'commission_status' => 0,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => time(),
        ]);
        DB::table('v2_order')->where('id', $order->id)->update([
            'paid_at' => $paidAt,
            'updated_at' => time(),
            'commission_status' => 0,
        ]);

        app(CheckCommission::class)->autoCheck();
        $order->refresh();

        $this->assertSame(
            1,
            (int) $order->commission_status,
            '计时应基于 paid_at：支付已满 3 天即使 updated_at 很新也应确认'
        );
    }

    public function test_string_true_enable_flag_must_not_disable_autocheck(): void
    {
        // 模拟设置里存成 JSON/字符串 true 时 (int)"true"===0 的坑
        admin_setting(['commission_auto_check_enable' => 'true']);

        $inviter = $this->user('e3-inv@example.com');
        $buyer = $this->user('e3-buy@example.com', $inviter->id);
        $oldTs = strtotime('-4 day');
        $order = $this->order($buyer, $inviter, [
            'commission_status' => 0,
            'paid_at' => $oldTs,
            'created_at' => $oldTs,
            'updated_at' => $oldTs,
        ]);
        DB::table('v2_order')->where('id', $order->id)->update([
            'paid_at' => $oldTs,
            'updated_at' => $oldTs,
        ]);

        // 当前实现 (int)'true' === 0 会跳过整个 autoCheck
        $enabledRaw = admin_setting('commission_auto_check_enable', 1);
        if ((int) $enabledRaw === 0 && filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN)) {
            // 文档化该坑
            $this->assertTrue(true, '检测到 (int) 会把字符串 true 当成关闭');
        }

        app(CheckCommission::class)->autoCheck();
        $order->refresh();

        $this->assertSame(
            1,
            (int) $order->commission_status,
            '开关值为字符串 true 时自动确认仍应生效，raw=' . var_export($enabledRaw, true)
        );
    }

    private function user(string $email, ?int $invite = null): User
    {
        $u = new User();
        $u->forceFill([
            'email' => $email,
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'invite_user_id' => $invite,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $u->save();
        return $u;
    }

    private function order(User $buyer, User $inviter, array $extra): Order
    {
        $o = new Order();
        $o->forceFill(array_merge([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => 1000,
            'invite_user_id' => $inviter->id,
            'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
        ], $extra));
        $o->save();
        return $o;
    }
}
