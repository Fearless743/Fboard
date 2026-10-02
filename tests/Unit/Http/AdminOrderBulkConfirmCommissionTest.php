<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\OrderController;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 管理端订单批量确认佣金：只把选中、有邀请人且有佣金的待确认订单推进到发放中(1)。
 */
class AdminOrderBulkConfirmCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_confirm_only_promotes_eligible_pending_commissions(): void
    {
        $inviter = $this->makeUser();
        $buyer = $this->makeUser($inviter->id);

        $pending = $this->makeOrder($buyer->id, $inviter->id, 1000, 0);
        $valid = $this->makeOrder($buyer->id, $inviter->id, 1000, 2);
        $noInviter = $this->makeOrder($buyer->id, null, 1000, 0);
        $noCommission = $this->makeOrder($buyer->id, $inviter->id, 0, 0);

        $response = app(OrderController::class)->bulkConfirmCommission(
            Request::create('/api/v2/admin/order/bulk-confirm-commission', 'POST', [
                'ids' => [$pending->id, $valid->id, $noInviter->id, $noCommission->id],
            ])
        );

        $payload = $response->getData(true);
        $this->assertSame('success', $payload['status']);
        $this->assertSame(1, (int) $payload['data']['count']);

        $this->assertSame(1, (int) $pending->refresh()->commission_status);
        $this->assertSame(2, (int) $valid->refresh()->commission_status, '已有效佣金不得被重置为发放中');
        $this->assertSame(0, (int) $noInviter->refresh()->commission_status);
        $this->assertSame(0, (int) $noCommission->refresh()->commission_status);
    }

    public function test_bulk_confirm_requires_non_empty_ids(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(OrderController::class)->bulkConfirmCommission(
            Request::create('/api/v2/admin/order/bulk-confirm-commission', 'POST', ['ids' => []])
        );
    }

    private function makeUser(?int $inviteUserId = null): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'commission-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'invite_user_id' => $inviteUserId,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        return $user;
    }

    private function makeOrder(int $userId, ?int $inviteUserId, int $commissionBalance, int $commissionStatus): Order
    {
        $order = new Order();
        $order->forceFill([
            'user_id' => $userId,
            'plan_id' => 1,
            'period' => 'monthly',
            'trade_no' => Helper::guid(),
            'total_amount' => 10000,
            'commission_balance' => $commissionBalance,
            'commission_status' => $commissionStatus,
            'invite_user_id' => $inviteUserId,
            'status' => Order::STATUS_COMPLETED,
            'type' => Order::TYPE_NEW_PURCHASE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order->save();

        return $order;
    }
}
