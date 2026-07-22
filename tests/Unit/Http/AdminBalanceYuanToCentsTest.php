<?php

namespace Tests\Unit\Http;

use App\Utils\Helper;
use Tests\TestCase;

/**
 * 管理端把「元」写成「分」时必须用 yuanToCents，禁止 float * 100。
 */
class AdminBalanceYuanToCentsTest extends TestCase
{
    public function test_float_multiply_100_can_lose_cents(): void
    {
        // 经典 IEEE 问题：19.99 * 100 在 float 下可能不是 1999
        $yuan = 19.99;
        $bad = (int) ($yuan * 100); // 截断
        $good = Helper::yuanToCents($yuan);

        // 若 bad != good，则证明控制器 *100 路径有风险
        // 用 assert 文档化：正确路径必须等于 1999
        $this->assertSame(1999, $good);

        // 源码回归：Admin UserController 必须用 yuanToCents（静态检查）
        $src = file_get_contents(base_path('app/Http/Controllers/V2/Admin/UserController.php'));
        $this->assertStringNotContainsString(
            "\$params['balance'] = \$params['balance'] * 100;",
            $src,
            'Admin UserController 不得使用 balance * 100'
        );
        $this->assertStringNotContainsString(
            "\$params['commission_balance'] = \$params['commission_balance'] * 100;",
            $src,
            'Admin UserController 不得使用 commission_balance * 100'
        );
        unset($bad);
    }
}
