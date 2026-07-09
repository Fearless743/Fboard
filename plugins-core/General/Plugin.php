<?php

namespace Plugin\General;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(General::class, AbstractProtocol::class)) {
                $protocols[] = General::class;
            }
            return $protocols;
        });
    }
}
