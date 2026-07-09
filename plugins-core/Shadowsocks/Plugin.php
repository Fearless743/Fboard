<?php

namespace Plugin\Shadowsocks;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Shadowsocks::class, AbstractProtocol::class)) {
                $protocols[] = Shadowsocks::class;
            }
            return $protocols;
        });
    }
}
