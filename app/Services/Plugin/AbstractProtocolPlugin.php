<?php

namespace App\Services\Plugin;

use App\Contracts\ProtocolPluginInterface;
use App\Support\AbstractProtocol;

abstract class AbstractProtocolPlugin extends AbstractPlugin implements ProtocolPluginInterface
{
    abstract public static function getProtocolClass(): string;

    public function boot(): void
    {
        $protocolClass = static::getProtocolClass();

        $this->filter('protocols.register', function ($protocols) use ($protocolClass) {
            if (is_subclass_of($protocolClass, AbstractProtocol::class)) {
                $protocols[] = $protocolClass;
            }
            return $protocols;
        });
    }
}
