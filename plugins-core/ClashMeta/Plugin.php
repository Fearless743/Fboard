<?php

namespace Plugin\ClashMeta;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(ClashMeta::class, AbstractProtocol::class)) {
                $protocols[] = ClashMeta::class;
            }
            return $protocols;
        });
    }
}
