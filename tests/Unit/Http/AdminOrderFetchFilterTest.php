<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\OrderController;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 管理端订单列表 filter：trade_no 为 generateOrderNo() 产出的纯数字长串，
 * 不可按 is_numeric 强转 int（会溢出/截断导致搜不到）。
 */
class AdminOrderFetchFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(string $tradeNo, int $status = Order::STATUS_PENDING): Order
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
            'email' => 'of-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        $order = new Order();
        $order->forceFill([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'total_amount' => 1000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => $status,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        return $order;
    }

    private function fetchData(array $filter, int $pageSize = 20): array
    {
        $controller = app(OrderController::class);
        $request = Request::create('/api/v2/admin/order/fetch', 'POST', [
            'current' => 1,
            'pageSize' => $pageSize,
            'filter' => $filter,
        ]);
        $response = $controller->fetch($request);
        $payload = $response->getData(true);

        // paginate() 返回扁平结构：{ total, data, ... }，无 success 包裹
        return $payload['data'] ?? [];
    }

    public function test_trade_no_full_numeric_order_no_is_findable(): void
    {
        // 与 Helper::generateOrderNo() 同形：YmdHms + micro + 5 位随机，纯数字且远超 PHP_INT_MAX
        $tradeNo = '2026072314304512345678901';
        $this->assertTrue(is_numeric($tradeNo));
        $this->assertNotSame($tradeNo, (string) (int) $tradeNo, '前置：int 截断会破坏订单号');

        $this->seedOrder($tradeNo);
        $this->seedOrder('2026072314304599999999999');

        $rows = $this->fetchData([['id' => 'trade_no', 'value' => $tradeNo]]);
        $tradeNos = array_column($rows, 'trade_no');

        $this->assertContains($tradeNo, $tradeNos);
        $this->assertCount(1, $rows, '应只命中完整订单号，不得因 int 截断匹配失败或乱匹配');
    }

    public function test_trade_no_partial_like_search(): void
    {
        $tradeNo = '2026072314304512345678901';
        $this->seedOrder($tradeNo);
        $this->seedOrder('1999010112000011111122222');

        $rows = $this->fetchData([['id' => 'trade_no', 'value' => '20260723']]);
        $tradeNos = array_column($rows, 'trade_no');

        $this->assertContains($tradeNo, $tradeNos);
        $this->assertNotContains('1999010112000011111122222', $tradeNos);
    }

    public function test_status_numeric_filter_still_exact(): void
    {
        $this->seedOrder(Helper::generateOrderNo(), Order::STATUS_PENDING);
        $this->seedOrder(Helper::generateOrderNo(), Order::STATUS_COMPLETED);

        $rows = $this->fetchData([['id' => 'status', 'value' => '0']]);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['status']);
        }
    }
}
