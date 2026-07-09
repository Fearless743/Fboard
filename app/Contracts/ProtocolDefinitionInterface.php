<?php

namespace App\Contracts;

use App\Support\ProtocolDefinition;

interface ProtocolDefinitionInterface
{
    public static function getProtocolDefinition(): ProtocolDefinition;
}
