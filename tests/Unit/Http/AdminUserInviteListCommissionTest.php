<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\UserController;
use App\Models\CommissionLog;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 管理端「邀请用户」弹窗：每个下线的佣金列应展示该邀请人从其身上实际赚取的佣金，
 * 而不是下线自己的 commission_balance（后者通常恒为 0）。
 */
class AdminUserInviteListCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_list_shows_commission_earned_per_invitee(): void
    {
        $inviter = $this->makeUser();
        $withCommission = $this->makeUser($inviter->id);
        $withoutCommission = $this->makeUser($inviter->id);
        $otherInviter = $this->makeUser();
        $otherInvitee = $this->makeUser($otherInviter->id);

        // 邀请人从下线身上赚到的佣金（可能跨越多个订单/层级）
        $this->makeCommissionLog($inviter->id, $withCommission->id, 300);
        $this->makeCommissionLog($inviter->id, $withCommission->id, 200);
        // 其他邀请人的日志不应被计入
        $this->makeCommissionLog($otherInviter->id, $otherInvitee->id, 999);

        $response = app(UserController::class)->inviteList(
            Request::create('/api/v2/admin/user/inviteList', 'GET', [
                'user_id' => $inviter->id,
                'current' => 1,
                'pageSize' => 10,
            ])
        );

        $payload = $response->getData(true);
        $this->assertSame(2, (int) $payload['total']);

        $byEmail = collect($payload['data'])->keyBy('invitee_email');
        $this->assertSame(
            5.0,
            (float) $byEmail[$withCommission->email]['commission_balance'],
            '下线佣金应为邀请人赚取的 300+200 分 = 5.00'
        );
        $this->assertSame(
            0.0,
            (float) $byEmail[$withoutCommission->email]['commission_balance']
        );
    }

    private function makeUser(?int $inviteUserId = null): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'invite-' . Helper::guid() . '@example.com',
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

    private function makeCommissionLog(int $inviteUserId, int $userId, int $getAmount): CommissionLog
    {
        $log = new CommissionLog();
        $log->forceFill([
            'invite_user_id' => $inviteUserId,
            'user_id' => $userId,
            'trade_no' => Helper::guid(),
            'order_amount' => 10000,
            'get_amount' => $getAmount,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $log->save();

        return $log;
    }
}
