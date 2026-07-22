<?php

namespace Tests\Unit\Services\Auth;

use App\Models\InviteCode;
use App\Models\User;
use App\Services\Auth\RegisterService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * invite_never_expire=0 时，一次性邀请码不得被两次 handleInviteCode 都消耗成功。
 */
class InviteCodeConcurrentTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_handle_invite_code_fails_when_already_used(): void
    {
        admin_setting([
            'invite_force' => 1,
            'invite_never_expire' => 0,
        ]);

        $inviter = new User();
        $inviter->forceFill([
            'email' => 'inv-' . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $inviter->save();

        $code = new InviteCode();
        $code->forceFill([
            'user_id' => $inviter->id,
            'code' => strtoupper(substr(md5(Helper::guid()), 0, 8)),
            'status' => InviteCode::STATUS_UNUSED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $code->save();

        $svc = app(RegisterService::class);
        $first = $svc->handleInviteCode($code->code);
        $this->assertSame($inviter->id, $first);

        $this->expectException(\App\Exceptions\ApiException::class);
        $svc->handleInviteCode($code->code);
    }
}
