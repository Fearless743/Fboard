<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\TicketController;
use App\Http\Requests\User\TicketWithdraw;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * 提现申请若只建工单不冻结佣金，同一余额可开多张提现单，运营按单打款会双付。
 * 期望：第二次 withdraw 应失败，或余额已被冻结/扣减。
 */
class WithdrawDoesNotFreezeCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_withdraw_must_not_succeed_with_same_unfrozen_balance(): void
    {
        admin_setting([
            'withdraw_close_enable' => 0,
            'commission_withdraw_limit' => 1, // 元
            'commission_withdraw_method' => Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT,
        ]);

        $user = new User();
        $user->forceFill([
            'email' => 'wd-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'commission_balance' => 10000, // 100 元
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();

        Auth::guard('sanctum')->setUser($user);

        $controller = app(TicketController::class);
        $method = Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT[0] ?? '支付宝';

        $req1 = TicketWithdraw::create('/api/v1/user/ticket/withdraw', 'POST', [
            'withdraw_method' => $method,
            'withdraw_account' => 'a@example.com',
        ]);
        $req1->setUserResolver(fn () => $user);
        $req1->setContainer(app())->setRedirector(app('redirect'));
        $req1->validateResolved();

        $res1 = $controller->withdraw($req1);
        $this->assertNotNull($res1);

        $user->refresh();
        // 申请成功后必须冻结/扣减佣金，工单内记录金额
        $this->assertSame(0, (int) $user->commission_balance, '提现申请必须扣减/冻结 commission_balance');
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());

        // 关闭工单后再次提现：余额已为 0，应被拒绝
        $ticket = Ticket::where('user_id', $user->id)->orderByDesc('id')->first();
        $this->assertNotNull($ticket);
        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->save();

        $req2 = TicketWithdraw::create('/api/v1/user/ticket/withdraw', 'POST', [
            'withdraw_method' => $method,
            'withdraw_account' => 'b@example.com',
        ]);
        $req2->setUserResolver(fn () => $user);
        $req2->setContainer(app())->setRedirector(app('redirect'));
        $req2->validateResolved();

        try {
            $controller->withdraw($req2);
            $this->fail('佣金已清零后第二次 withdraw 应失败');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertTrue(true, $e->getMessage());
        }

        $user->refresh();
        $this->assertSame(0, (int) $user->commission_balance);
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }
}
