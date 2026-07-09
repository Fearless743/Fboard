<?php

namespace Plugin\SingBox;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(SingBox::class, AbstractProtocol::class)) {
                $protocols[] = SingBox::class;
            }
            return $protocols;
        });
    }
}
