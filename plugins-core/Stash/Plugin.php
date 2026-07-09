<?php

namespace Plugin\Stash;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(Stash::class, AbstractProtocol::class)) {
                $protocols[] = Stash::class;
            }
            return $protocols;
        });
    }
}
