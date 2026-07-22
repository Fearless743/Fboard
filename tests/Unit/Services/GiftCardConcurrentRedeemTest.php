<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\User;
use App\Services\GiftCardService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 兑换码 max_usage=1 时，两份已加载的 Service 实例不得先后各兑成功一次。
 * 复现：构造时各读一次未使用码，redeem 内无行锁/二次校验即可双发奖励。
 */
class GiftCardConcurrentRedeemTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_redeem_on_same_code_is_rejected(): void
    {
        [$code, $userA, $userB] = $this->seedCodeAndUsers();

        $svcA = (new GiftCardService($code->code))->setUser($userA);
        $svcB = (new GiftCardService($code->code))->setUser($userB);

        $svcA->redeem();

        $this->expectException(ApiException::class);
        $svcB->redeem();
    }

    public function test_after_first_redeem_code_is_used_once_and_balance_not_doubled(): void
    {
        [$code, $userA, $userB] = $this->seedCodeAndUsers(balanceReward: 5000);

        $svcA = (new GiftCardService($code->code))->setUser($userA);
        $svcB = (new GiftCardService($code->code))->setUser($userB);

        $svcA->redeem();

        $failed = false;
        try {
            $svcB->redeem();
        } catch (ApiException) {
            $failed = true;
        }
        $this->assertTrue($failed, '第二次兑换必须失败');

        $code->refresh();
        $this->assertSame(GiftCardCode::STATUS_USED, (int) $code->status);
        $this->assertSame(1, (int) $code->usage_count);
        $this->assertSame(1, GiftCardUsage::where('code_id', $code->id)->count());

        $userA->refresh();
        $userB->refresh();
        $this->assertSame(5000, (int) $userA->balance);
        $this->assertSame(0, (int) $userB->balance);
    }

    /**
     * @return array{0: GiftCardCode, 1: User, 2: User}
     */
    private function seedCodeAndUsers(int $balanceReward = 1000): array
    {
        $template = new GiftCardTemplate();
        $template->forceFill([
            'name' => 'balance-card',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true,
            'rewards' => ['balance' => $balanceReward],
            'conditions' => [],
            'limits' => [],
            'admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $template->save();

        $code = new GiftCardCode();
        $code->forceFill([
            'template_id' => $template->id,
            'code' => 'GC' . strtoupper(substr(md5(Helper::guid()), 0, 12)),
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $code->save();

        $userA = $this->makeUser('a');
        $userB = $this->makeUser('b');

        return [$code, $userA, $userB];
    }

    private function makeUser(string $tag): User
    {
        $user = new User();
        $user->forceFill([
            'email' => "gift-{$tag}-" . Helper::guid() . '@example.com',
            'password' => password_hash('p', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user->save();
        return $user;
    }
}
