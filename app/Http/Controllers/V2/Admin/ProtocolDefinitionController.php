<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProtocolDefinitionRegistry;

class ProtocolDefinitionController extends Controller
{
    public function __construct(
        private readonly ProtocolDefinitionRegistry $registry
    ) {}

    public function index()
    {
        return $this->success($this->registry->toArray());
    }

    public function show(string $type)
    {
        $definition = $this->registry->get($type);
        if (!$definition) {
            return $this->fail([404, '协议类型不存在']);
        }
        return $this->success($definition->toArray());
    }

    public function types()
    {
        $definitions = $this->registry->getAll();
        $types = [];
        $index = 1;
        foreach ($definitions as $type => $definition) {
            $types[] = [
                'id' => $index,
                'type' => $definition->type,
                'name' => $definition->name,
            ];
            $index++;
        }
        return $this->success($types);
    }

    public function configFields(string $type)
    {
        $definition = $this->registry->get($type);
        if (!$definition) {
            return $this->fail([404, '协议类型不存在']);
        }
        return $this->success([
            'type' => $definition->type,
            'name' => $definition->name,
            'config_fields' => $definition->configFields,
        ]);
    }

    public function validationRules(string $type)
    {
        $definition = $this->registry->get($type);
        if (!$definition) {
            return $this->fail([404, '协议类型不存在']);
        }
        return $this->success([
            'type' => $definition->type,
            'rules' => $definition->validationRules,
        ]);
    }
}
