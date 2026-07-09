<?php

namespace Plugin\Clash;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Clash::class, AbstractProtocol::class)) {
                $protocols[] = Clash::class;
            }
            return $protocols;
        });
    }
}
