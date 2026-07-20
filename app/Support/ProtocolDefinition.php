<?php

namespace App\Support;

class ProtocolDefinition
{
    /**
     * @param string $type 协议类型标识
     * @param string $name 协议显示名称
     * @param array $configFields 配置字段定义
     * @param array $validationRules 验证规则
     * @param string|null $description 描述
     * @param string|array|null $prefix 服务器名称前缀，字符串如 '[ss]'，或版本依赖数组如 [1=>'[Hy]', 2=>'[Hy2]']
     * @param array|null $serverConfigBuilder 服务端配置构建器
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $configFields = [],
        public readonly array $validationRules = [],
        public readonly ?string $description = null,
        public readonly string|array|null $prefix = null,
        public readonly ?array $serverConfigBuilder = null,
    ) {}

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
        ];
    }
}
