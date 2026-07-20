<?php

namespace App\Services;

use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Support\ProtocolDefinition;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;

class ProtocolDefinitionRegistry
{
    private array $definitions = [];
    private bool $loaded = false;
    private bool $initializing = false;

    public function register(ProtocolDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    public function registerFromArray(string $type, string $name, array $configFields, array $validationRules = [], ?string $description = null, string|array|null $prefix = null): void
    {
        $this->register(new ProtocolDefinition(
            type: $type,
            name: $name,
            configFields: $configFields,
            validationRules: $validationRules,
            description: $description,
            prefix: $prefix,
        ));
    }

    public function getAll(): array
    {
        $this->loadIfNeeded();
        return $this->definitions;
    }

    public function get(string $type): ?ProtocolDefinition
    {
        $this->loadIfNeeded();
        return $this->definitions[$type] ?? null;
    }

    public function getValidTypes(): array
    {
        return array_keys($this->getAll());
    }

    public function getConfigFields(string $type): array
    {
        $def = $this->get($type);
        return $def ? $def->configFields : [];
    }

    public function getValidationRules(string $type): array
    {
        $def = $this->get($type);
        return $def ? $def->validationRules : [];
    }

    public function toArray(): array
    {
        return array_map(fn(ProtocolDefinition $d) => $d->toArray(), $this->getAll());
    }

    public function reset(): void
    {
        $this->definitions = [];
        $this->loaded = false;
    }

    private function loadIfNeeded(): void
    {
        if ($this->loaded || $this->initializing) {
            return;
        }
        $this->initializing = true;

        // 确保插件已初始化（钩子已注册）
        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Throwable $e) {
            Log::error('Failed to initialize plugins for protocol definitions: ' . $e->getMessage());
        }

        $this->loadPluginDefinitions();

        $this->loaded = true;
        $this->initializing = false;
    }

    private function loadPluginDefinitions(): void
    {
        $pluginDefinitions = HookManager::filter('protocols.definitions', []);
        foreach ($pluginDefinitions as $type => $data) {
            if ($data instanceof ProtocolDefinition) {
                $this->definitions[$type] = $this->withDynamicUtls($data);
            } elseif (is_array($data)) {
                $this->definitions[$type] = $this->withDynamicUtls(new ProtocolDefinition(
                    type: $type,
                    name: $data['name'] ?? $type,
                    configFields: $data['config_fields'] ?? $data['configFields'] ?? [],
                    validationRules: $data['validation_rules'] ?? $data['validationRules'] ?? [],
                    description: $data['description'] ?? null,
                    prefix: $data['prefix'] ?? null,
                ));
            }
        }
    }

    /**
     * 将系统设置中的 uTLS 指纹列表注入协议定义（下拉 options + 校验 in 规则）。
     * 确保编辑节点时可选指纹与 Config 节点配置保持一致。
     */
    private function withDynamicUtls(ProtocolDefinition $definition): ProtocolDefinition
    {
        $fields = $definition->configFields;
        $rules = $definition->validationRules;

        if (!isset($fields['utls']) || !is_array($fields['utls'])) {
            return $definition;
        }

        $options = Helper::getUtlsFingerprintOptions();
        $fields['utls']['fields'] = $fields['utls']['fields'] ?? [];
        if (isset($fields['utls']['fields']['fingerprint']) && is_array($fields['utls']['fields']['fingerprint'])) {
            $fields['utls']['fields']['fingerprint']['options'] = $options;
        }

        $allowed = array_keys($options);
        if ($allowed !== []) {
            $rules['utls.fingerprint'] = 'nullable|string|in:' . implode(',', $allowed);
        }

        return new ProtocolDefinition(
            type: $definition->type,
            name: $definition->name,
            configFields: $fields,
            validationRules: $rules,
            description: $definition->description,
            prefix: $definition->prefix,
            serverConfigBuilder: $definition->serverConfigBuilder,
        );
    }
}
