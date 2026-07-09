<?php

namespace Plugin\Surfboard;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Surfboard::class, AbstractProtocol::class)) {
                $protocols[] = Surfboard::class;
            }
            return $protocols;
        });
    }
}
