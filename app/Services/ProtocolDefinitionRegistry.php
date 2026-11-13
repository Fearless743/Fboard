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

    /** @var array<string, string> alias => canonical type */
    private array $aliases = [];

    private bool $loaded = false;
    private bool $initializing = false;

    /**
     * 协议字段 → 订阅侧 casting 配置的派生缓存（请求级）。
     *
     * @var array<string, array<string, array<string, mixed>>>|null
     */
    private ?array $castingConfigurations = null;

    public function register(ProtocolDefinition $definition): void
    {
        $this->castingConfigurations = null;
        $this->definitions[$definition->type] = $definition;
        foreach ($definition->aliases as $alias) {
            $alias = strtolower((string) $alias);
            if ($alias !== '' && $alias !== $definition->type) {
                $this->aliases[$alias] = $definition->type;
            }
        }
    }

    public function registerFromArray(
        string $type,
        string $name,
        array $configFields,
        array $validationRules = [],
        ?string $description = null,
        string|array|null $prefix = null,
        mixed $serverConfigBuilder = null,
        mixed $passwordGenerator = null,
        array $aliases = [],
        bool $transformNodeUserUuid = false,
        mixed $serverKeyResolver = null,
    ): void {
        $this->register(new ProtocolDefinition(
            type: $type,
            name: $name,
            configFields: $configFields,
            validationRules: $validationRules,
            description: $description,
            prefix: $prefix,
            serverConfigBuilder: $serverConfigBuilder,
            passwordGenerator: $passwordGenerator,
            aliases: $aliases,
            transformNodeUserUuid: $transformNodeUserUuid,
            serverKeyResolver: $serverKeyResolver,
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
        $canonical = $this->normalizeType($type);

        return $this->definitions[$canonical] ?? $this->definitions[$type] ?? null;
    }

    public function getValidTypes(): array
    {
        return array_keys($this->getAll());
    }

    /**
     * 协议字段定义转换为订阅侧 casting 配置，按请求缓存。
     *
     * 订阅下发会为每个节点调用一次；没有缓存时每个节点都要遍历全部协议定义。
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getCastingConfigurations(): array
    {
        if ($this->castingConfigurations !== null) {
            return $this->castingConfigurations;
        }

        $configs = [];
        foreach ($this->getAll() as $type => $definition) {
            $configs[$type] = [];
            foreach ($definition->configFields as $key => $field) {
                if (!isset($field['type'])) {
                    continue;
                }
                $configs[$type][$key] = [
                    'type' => $field['type'],
                    'default' => $field['default'] ?? null,
                ];
                if ($field['type'] === 'object' && isset($field['fields'])) {
                    $configs[$type][$key]['fields'] = $field['fields'];
                }
            }
        }

        return $this->castingConfigurations = $configs;
    }

    /**
     * 将别名规范为协议 type（小写）。未知别名原样返回小写字符串。
     */
    public function normalizeType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $this->loadIfNeeded();
        $lower = strtolower($type);

        return $this->aliases[$lower] ?? $lower;
    }

    /**
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        $this->loadIfNeeded();
        return $this->aliases;
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
        $this->aliases = [];
        $this->loaded = false;
        $this->castingConfigurations = null;
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
                $this->register($this->withDynamicUtls($data));
            } elseif (is_array($data)) {
                $this->register($this->withDynamicUtls(new ProtocolDefinition(
                    type: $type,
                    name: $data['name'] ?? $type,
                    configFields: $data['config_fields'] ?? $data['configFields'] ?? [],
                    validationRules: $data['validation_rules'] ?? $data['validationRules'] ?? [],
                    description: $data['description'] ?? null,
                    prefix: $data['prefix'] ?? null,
                    serverConfigBuilder: $data['server_config_builder']
                        ?? $data['serverConfigBuilder']
                        ?? null,
                    passwordGenerator: $data['password_generator']
                        ?? $data['passwordGenerator']
                        ?? null,
                    aliases: array_values($data['aliases'] ?? []),
                    transformNodeUserUuid: (bool) ($data['transform_node_user_uuid']
                        ?? $data['transformNodeUserUuid']
                        ?? false),
                    serverKeyResolver: $data['server_key_resolver']
                        ?? $data['serverKeyResolver']
                        ?? null,
                )));
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
            passwordGenerator: $definition->passwordGenerator,
            aliases: $definition->aliases,
            transformNodeUserUuid: $definition->transformNodeUserUuid,
            serverKeyResolver: $definition->serverKeyResolver,
        );
    }
}
