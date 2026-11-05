<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Controllers\V1\Passport\PasskeyController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', [AuthController::class, 'register'])->middleware(['throttle:auth', 'maintenance']);
            $router->post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
            $router->get('/auth/token2Login', [AuthController::class, 'token2Login'])->middleware('throttle:auth');
            $router->post('/auth/forget', [AuthController::class, 'forget'])->middleware(['throttle:auth', 'maintenance']);
            $router->post('/auth/getQuickLoginUrl', [AuthController::class, 'getQuickLoginUrl'])->middleware(['throttle:auth', 'maintenance']);
            $router->post('/auth/loginWithMailLink', [AuthController::class, 'loginWithMailLink'])->middleware('throttle:auth');
            // Passkey 二步（管理员强制）：ticket 机制，无需登录态
            $router->post('/auth/passkey/auth-options', [PasskeyController::class, 'authOptions'])->middleware('throttle:auth');
            $router->post('/auth/passkey/auth-verify', [PasskeyController::class, 'authVerify'])->middleware('throttle:auth');
            $router->post('/auth/passkey/register-options-setup', [PasskeyController::class, 'registerOptionsSetup'])->middleware('throttle:auth');
            $router->post('/auth/passkey/register-verify-setup', [PasskeyController::class, 'registerVerifySetup'])->middleware('throttle:auth');
            // Comm
            $router->post('/comm/sendEmailVerify', [CommController::class, 'sendEmailVerify'])->middleware(['throttle:auth', 'maintenance']);
            $router->post('/comm/pv', [CommController::class, 'pv'])->middleware('maintenance');
        });
    }
}
