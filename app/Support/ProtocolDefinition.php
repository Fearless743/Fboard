<?php

namespace App\Support;

use App\Models\Server;
use App\Models\User;

class ProtocolDefinition
{
    /**
     * @param string $type 协议类型标识
     * @param string $name 协议显示名称
     * @param array $configFields 配置字段定义
     * @param array $validationRules 验证规则
     * @param string|null $description 描述
     * @param string|array|null $prefix 服务器名称前缀，字符串如 '[ss]'，或版本依赖数组如 [1=>'[Hy]', 2=>'[Hy2]']
     * @param callable|null $serverConfigBuilder 节点下发配置构建器
     *        签名：fn(Server $node, array $baseConfig): array
     * @param callable|null $passwordGenerator 用户密码/凭证生成器
     *        签名：fn(Server $node, User $user): string；默认返回 $user->uuid
     * @param list<string> $aliases 类型别名（如 hysteria2 → hysteria），用于 normalizeType
     * @param bool $transformNodeUserUuid 下发节点用户列表时是否用 passwordGenerator 覆盖 uuid 字段
     * @param callable|null $serverKeyResolver 订阅侧 server_key 访问器
     *        签名：fn(Server $node): mixed
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $configFields = [],
        public readonly array $validationRules = [],
        public readonly ?string $description = null,
        public readonly string|array|null $prefix = null,
        public readonly mixed $serverConfigBuilder = null,
        public readonly mixed $passwordGenerator = null,
        public readonly array $aliases = [],
        public readonly bool $transformNodeUserUuid = false,
        public readonly mixed $serverKeyResolver = null,
    ) {}

    /**
     * 构建节点守护进程所需的协议配置。
     * 无 builder 时返回 $baseConfig（未知协议仅含公共字段）。
     *
     * @param  array<string, mixed>  $baseConfig
     * @return array<string, mixed>
     */
    public function buildServerConfig(Server $node, array $baseConfig): array
    {
        if (!is_callable($this->serverConfigBuilder)) {
            return $baseConfig;
        }

        $built = ($this->serverConfigBuilder)($node, $baseConfig);

        return is_array($built) ? $built : $baseConfig;
    }

    /**
     * 生成用户侧密码/凭证（订阅 password 字段等）。
     * 无生成器时回退为用户 UUID。
     */
    public function generatePassword(Server $node, User $user): string
    {
        if (!is_callable($this->passwordGenerator)) {
            return (string) $user->uuid;
        }

        $result = ($this->passwordGenerator)($node, $user);

        return is_string($result) && $result !== ''
            ? $result
            : (string) $user->uuid;
    }

    /**
     * 订阅侧 server_key（如 Shadowsocks 密钥摘要）。无 resolver 时返回 null。
     */
    public function resolveServerKey(Server $node): mixed
    {
        if (!is_callable($this->serverKeyResolver)) {
            return null;
        }

        return ($this->serverKeyResolver)($node);
    }

    /**
     * 根据服务器信息获取名称前缀
     */
    public function getServerNamePrefix(array $server): string
    {
        if ($this->prefix === null) {
            return '';
        }

        if (is_array($this->prefix)) {
            $version = $server['protocol_settings']['version'] ?? 1;
            return $this->prefix[$version] ?? '';
        }

        return $this->prefix;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'config_fields' => $this->configFields,
            // 管理端表单据此标记协议必填项（与 ServerSave 校验一致）
            'validation_rules' => $this->validationRules,
            'aliases' => $this->aliases,
        ];
    }
}
