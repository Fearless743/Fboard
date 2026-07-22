<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Guest\PaymentController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PaymentNotifyHandleTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_returns_false_when_order_missing(): void
    {
        $controller = new PaymentController();
        $method = new ReflectionMethod($controller, 'handle');
        $method->setAccessible(true);

        $result = $method->invoke($controller, 'non-existent-trade-no', 'cb-1');

        $this->assertFalse(
            $result,
            '订单不存在时 handle 必须返回 false，避免 notify() 输出 success'
        );
        $this->assertIsBool($result);
    }
}
