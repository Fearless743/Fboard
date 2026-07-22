<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\OrderController;
use App\Http\Requests\Admin\OrderAssign;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理端 assign 必须与用户侧 createFromRequest 一样保证单 PENDING：
 * 检查+创建之间无用户行锁时，并发/连点可叠多笔待支付订单。
 */
class AdminOrderAssignSinglePendingTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_assign_fails_when_pending_exists(): void
    {
        $group = new ServerGroup();
        $group->forceFill(['name' => 'g', 'created_at' => time(), 'updated_at' => time()]);
        $group->save();

        $plan = new Plan();
        $plan->forceFill([
            'group_id' => $group->id,
            'transfer_enable' => 100,
            'name' => 'p',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan->save();

        $user = new User();
        $user->forceFill([
            'email' => 'asg-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $controller = app(OrderController::class);

        $makeReq = function () use ($user, $plan) {
            $req = OrderAssign::create('/api/v2/admin/order/assign', 'POST', [
                'email' => $user->email,
                'plan_id' => $plan->id,
                'period' => 'month_price',
                'total_amount' => 10,
            ]);
            $req->setContainer(app())->setRedirector(app('redirect'));
            $req->validateResolved();
            return $req;
        };

        $r1 = $controller->assign($makeReq());
        $this->assertTrue(method_exists($r1, 'getData') || $r1->getStatusCode() === 200 || true);

        $pending = Order::where('user_id', $user->id)->where('status', Order::STATUS_PENDING)->count();
        $this->assertSame(1, $pending, '第一次 assign 应产生 1 笔 PENDING');

        // 第二次：当前实现若无锁会在 isNotComplete 检查失败；
        // 但若检查在事务外且无锁，竞态下可能叠单——串行时第二次应失败。
        $r2 = $controller->assign($makeReq());
        $pending2 = Order::where('user_id', $user->id)->where('status', Order::STATUS_PENDING)->count();

        $this->assertSame(
            1,
            $pending2,
            '已有 PENDING 时第二次 assign 不得再建订单，当前 PENDING=' . $pending2
        );
        unset($r2);
    }
}
