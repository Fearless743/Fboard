<?php

namespace App\Providers;

use App\Support\ProtocolManager;
use Illuminate\Support\ServiceProvider;

class ProtocolServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->scoped('protocols.manager', function ($app) {
            return new ProtocolManager($app);
        });

        $this->app->scoped('protocols.flags', function ($app) {
            return $app->make('protocols.manager')->getAllFlags();
        });
    }

    public function boot()
    {
    }

    public function provides()
    {
        return [
            'protocols.manager',
            'protocols.flags',
        ];
    }
}
