<?php

namespace Plugin\Shadowrocket;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Shadowrocket::class, AbstractProtocol::class)) {
                $protocols[] = Shadowrocket::class;
            }
            return $protocols;
        });
    }
}
