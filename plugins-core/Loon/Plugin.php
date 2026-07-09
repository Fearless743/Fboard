<?php

namespace Plugin\Loon;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Loon::class, AbstractProtocol::class)) {
                $protocols[] = Loon::class;
            }
            return $protocols;
        });
    }
}
