<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\BalanceDepositController;
use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\CouponController;
use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\KnowledgeController;
use App\Http\Controllers\V1\User\NoticeController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Controllers\V1\User\PlanController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V1\User\StatController;
use App\Http\Controllers\V1\User\TelegramController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V1\User\WithdrawalController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $auth = ['user', 'maintenance'];

        // 顶层别名：/api/v1/balance_deposit/*、/api/v1/balance_recharge/*
        $router->group(['middleware' => $auth], function ($router) {
            foreach (['balance_deposit', 'balance_recharge'] as $prefix) {
                $router->group(['prefix' => $prefix], function ($router) {
                    $router->post('/create', [BalanceDepositController::class, 'create']);
                    $router->get('/detail', [BalanceDepositController::class, 'detail']);
                });
            }
        });

        $router->group([
            'prefix' => 'user',
            'middleware' => $auth,
        ], function ($router) {
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/changePassword', [UserController::class, 'changePassword']);
            $router->post('/update', [UserController::class, 'update']);
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);
            $router->get('/getStat', [UserController::class, 'getStat']);
            $router->get('/checkLogin', [UserController::class, 'checkLogin']);
            $router->post('/transfer', [UserController::class, 'transfer']);
            $router->post('/getQuickLoginUrl', [UserController::class, 'getQuickLoginUrl']);
            $router->get('/getActiveSession', [UserController::class, 'getActiveSession']);
            $router->post('/removeActiveSession', [UserController::class, 'removeActiveSession']);
            // Order
            $router->post('/order/save', [OrderController::class, 'save']);
            $router->post('/order/checkout', [OrderController::class, 'checkout']);
            $router->get('/order/check', [OrderController::class, 'check']);
            $router->get('/order/detail', [OrderController::class, 'detail']);
            $router->get('/order/fetch', [OrderController::class, 'fetch']);
            $router->get('/order/getPaymentMethod', [OrderController::class, 'getPaymentMethod']);
            $router->post('/order/cancel', [OrderController::class, 'cancel']);
            // Balance deposit（与 order 同级）
            $router->group(['prefix' => 'balance_deposit'], function ($router) {
                $router->post('/create', [BalanceDepositController::class, 'create']);
                $router->get('/detail', [BalanceDepositController::class, 'detail']);
            });
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // Invite
            $router->get('/invite/save', [InviteController::class, 'save']);
            $router->get('/invite/fetch', [InviteController::class, 'fetch']);
            $router->get('/invite/details', [InviteController::class, 'details']);
            // Notice
            $router->get('/notice/fetch', [NoticeController::class, 'fetch']);
            // Ticket
            $router->post('/ticket/reply', [TicketController::class, 'reply']);
            $router->post('/ticket/close', [TicketController::class, 'close']);
            $router->post('/ticket/save', [TicketController::class, 'save']);
            $router->get('/ticket/fetch', [TicketController::class, 'fetch']);
            $router->post('/ticket/withdraw', [TicketController::class, 'withdraw']);
            // Withdrawal
            $router->get('/withdrawal/fetch', [WithdrawalController::class, 'fetch']);
            $router->post('/withdrawal/detail', [WithdrawalController::class, 'detail']);
            $router->post('/withdrawal/reply', [WithdrawalController::class, 'reply']);
            $router->post('/withdrawal/close', [WithdrawalController::class, 'close']);
            // Server
            $router->get('/server/fetch', [ServerController::class, 'fetch']);
            // Coupon
            $router->post('/coupon/check', [CouponController::class, 'check']);
            // Gift Card
            $router->post('/gift-card/check', [GiftCardController::class, 'check']);
            $router->post('/gift-card/redeem', [GiftCardController::class, 'redeem']);
            $router->get('/gift-card/history', [GiftCardController::class, 'history']);
            $router->get('/gift-card/detail', [GiftCardController::class, 'detail']);
            $router->get('/gift-card/types', [GiftCardController::class, 'types']);
            // Telegram
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
            $router->Post('/comm/getStripePublicKey', [CommController::class, 'getStripePublicKey']);
            // Knowledge
            $router->get('/knowledge/fetch', [KnowledgeController::class, 'fetch']);
            $router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);
            // Stat
            $router->get('/stat/getTrafficLog', [StatController::class, 'getTrafficLog']);
        });
    }
}
