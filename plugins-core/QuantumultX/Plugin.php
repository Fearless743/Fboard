<?php

namespace Plugin\QuantumultX;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(QuantumultX::class, AbstractProtocol::class)) {
                $protocols[] = QuantumultX::class;
            }
            return $protocols;
        });
    }
}
