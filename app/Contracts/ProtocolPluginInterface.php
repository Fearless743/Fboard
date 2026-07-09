<?php

namespace App\Contracts;

interface ProtocolPluginInterface
{
    public function getProtocolClass(): string;
}
