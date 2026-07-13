<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', [AuthController::class, 'register'])->middleware('maintenance');
            $router->post('/auth/login', [AuthController::class, 'login']);
            $router->get ('/auth/token2Login', [AuthController::class, 'token2Login']);
            $router->post('/auth/forget', [AuthController::class, 'forget'])->middleware('maintenance');
            $router->post('/auth/getQuickLoginUrl', [AuthController::class, 'getQuickLoginUrl'])->middleware('maintenance');
            $router->post('/auth/loginWithMailLink', [AuthController::class, 'loginWithMailLink']);
            // Comm
            $router->post('/comm/sendEmailVerify', [CommController::class, 'sendEmailVerify'])->middleware('maintenance');
            $router->post('/comm/pv', [CommController::class, 'pv'])->middleware('maintenance');
        });
    }
}
