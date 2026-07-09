<?php

namespace App\Support;

class ProtocolDefinition
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $configFields = [],
        public readonly array $validationRules = [],
        public readonly ?string $description = null,
        public readonly ?array $serverConfigBuilder = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'config_fields' => $this->configFields,
        ];
    }
}
