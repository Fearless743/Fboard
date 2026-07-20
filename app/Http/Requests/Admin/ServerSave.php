<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use App\Services\ProtocolDefinitionRegistry;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::getValidTypes()),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'custom_outbounds' => 'nullable|array',
            'custom_routes' => 'nullable|array',
            'cert_config' => 'nullable|array',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
            'transfer_enable' => 'nullable|integer|min:0',
        ];
    }

    private function getProtocolRules(?string $type): array
    {
        if ($type === null || $type === '') {
            return [];
        }

        $registry = app(ProtocolDefinitionRegistry::class);
        $definition = $registry->get($type);

        if (!$definition || empty($definition->validationRules)) {
            return [];
        }

        return $definition->validationRules;
    }

    /**
     * Partial update rules for manage/update when only a few fields are sent
     * (e.g. show toggle sends {id, show} without type/name/host).
     */
    private function getPartialUpdateRules(): array
    {
        return [
            'id' => 'required|integer',
            'show' => 'nullable',
            'enabled' => 'nullable|boolean',
            'name' => 'nullable|string',
            'group_ids' => 'nullable|array',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'host' => 'nullable',
            'port' => 'nullable',
            'server_port' => 'nullable',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'nullable|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'custom_outbounds' => 'nullable|array',
            'custom_routes' => 'nullable|array',
            'cert_config' => 'nullable|array',
            'protocol_settings' => 'nullable|array',
            'transfer_enable' => 'nullable|integer|min:0',
            'code' => 'nullable|string',
            'spectific_key' => 'nullable|string',
        ];
    }

    public function rules(): array
    {
        $type = $this->input('type');

        // manage/update is reused for partial field patches (show/enabled toggle).
        // Full create/save always includes type; partial updates often do not.
        if ($type === null || $type === '') {
            return $this->getPartialUpdateRules();
        }

        $rules = $this->getBaseRules();
        $protocolRules = $this->getProtocolRules(is_string($type) ? $type : null);

        foreach ($protocolRules as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    public function attributes(): array
    {
        $registry = app(ProtocolDefinitionRegistry::class);
        $protocolDefinitions = $registry->getAll();
        $attributes = [];

        foreach ($protocolDefinitions as $type => $definition) {
            $this->buildAttributesFromFields($definition->configFields, 'protocol_settings.', $attributes);
        }

        return $attributes;
    }

    private function buildAttributesFromFields(array $fields, string $prefix, array &$attributes): void
    {
        foreach ($fields as $key => $field) {
            if (isset($field['label'])) {
                $attributes[$prefix . $key] = $field['label'];
            }
            if ($field['type'] === 'object' && isset($field['fields'])) {
                $this->buildAttributesFromFields($field['fields'], $prefix . $key . '.', $attributes);
            }
        }
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误',
            'protocol_settings.*.required' => ':attribute 不能为空',
            'protocol_settings.*.required_if' => ':attribute 不能为空',
            'protocol_settings.*.string' => ':attribute 必须是字符串',
            'protocol_settings.*.integer' => ':attribute 必须是整数',
            'protocol_settings.*.in' => ':attribute 的值不合法',
            'protocol_settings.httpmask.path_root.regex' => '路径前缀 (path-root) 仅允许单段 [A-Za-z0-9_-]，例如 aabbcc 或 /aabbcc/，不可含点号或多级路径',
            'transfer_enable.integer' => '流量上限必须是整数',
            'transfer_enable.min' => '流量上限不能小于0',
        ];
    }
}
