<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\CommController;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\Guest\PlanController;
use App\Http\Controllers\V1\Guest\TelegramController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest',
            'middleware' => 'throttle:60,1'
        ], function ($router) {
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // Telegram
            $router->post('/telegram/webhook', [TelegramController::class, 'webhook']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
        });

        // 支付网关回调：不得按 IP 限流，否则网关重试/并发通知会触发 "Too Many Attempts."
        // 该端点自身通过签名/uuid 校验，无须 throttle 保护。
        $router->match(['get', 'post'], '/guest/payment/notify/{method}/{uuid}', [PaymentController::class, 'notify']);
    }
}
