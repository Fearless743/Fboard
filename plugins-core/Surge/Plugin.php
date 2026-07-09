<?php

namespace Plugin\Surge;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Surge::class, AbstractProtocol::class)) {
                $protocols[] = Surge::class;
            }
            return $protocols;
        });
    }
}
