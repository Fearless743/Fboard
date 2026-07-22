# 协议插件开发指南

协议插件将内部服务器配置转换为客户端可读的订阅格式（YAML、JSON、URI 等）。每个协议插件独立运行，系统自动发现。

> **注意**：XBoard 有两类「协议插件」：
> 1. **输出格式插件**（本文上半部分）— 将服务器配置转换为客户端订阅格式，如 Clash、SingBox
> 2. **协议类型定义插件**（本文下半部分）— 定义服务器支持的协议类型及其配置字段，如 Shadowsocks、VMess

---

# 第一部分：输出格式插件

## 📦 插件结构

```
plugins-core/
└── YourProtocol/            # 目录名 = 大驼峰 (如 ClashMeta、SingBox)
    ├── config.json          # 插件元信息 (必需)
    ├── Plugin.php           # 插件引导 (必需)
    └── YourProtocol.php     # 协议实现 (必需)
```

也可放在 `plugins/`（用户安装），结构相同。

## 📄 文件规范

### 1. config.json

```json
{
    "name": "Your Protocol Name",
    "code": "your_protocol",
    "type": "protocol",
    "version": "1.0.0",
    "description": "协议功能描述",
    "author": "Your Name"
}
```

| 字段 | 说明 |
|------|------|
| `type` | 必须为 `"protocol"` |
| `code` | 小写+下划线，用于数据库安装 |
| `version` | 语义化版本 (`\d+.\d+.\d+`) |

### 2. Plugin.php

```php
<?php

namespace Plugin\YourProtocol;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(YourProtocol::class, AbstractProtocol::class)) {
                $protocols[] = YourProtocol::class;
            }
            return $protocols;
        });
    }
}
```

`boot()` 方法通过 `protocols.register` 钩子注册协议类。`Plugin.php` 仅用于数据库生命周期管理（安装/启用/禁用）。协议发现也通过 `ProtocolManager` 的文件系统扫描完成。

### 3. YourProtocol.php

协议类必须继承 `App\Support\AbstractProtocol` 并实现 `handle()` 方法。

```php
<?php

namespace Plugin\YourProtocol;

use App\Support\AbstractProtocol;
use Plugin\CoreProtocols\ProtocolTypes;

class YourProtocol extends AbstractProtocol
{
    public $flags = ['your_client_flag1', 'flag2'];

    public $allowedProtocols = [
        ProtocolTypes::VMESS,
        ProtocolTypes::SHADOWSOCKS,
    ];

    protected $protocolRequirements = [];

    public function handle()
    {
        // 将 $this->servers 转换为客户端格式
        // 返回 response 对象
    }
}
```

## 🔧 核心属性

### `$flags` (public)

触发此协议的 User-Agent 字符串数组。系统将对照客户端的 User-Agent 头或 `?flag=` 参数进行匹配。

```php
public $flags = ['clash', 'verge', 'nekobox'];
```

匹配方式是不区分大小写的子串匹配。协议按发现的逆序检查，后发现的协议（用户插件）可覆盖先发现的（核心插件）。

### `$allowedProtocols` (public)

此协议支持的服务器类型白名单。在 `handle()` 调用前会自动过滤掉不支持的类型的服务器。

```php
public $allowedProtocols = [
    ProtocolTypes::VMESS,
    ProtocolTypes::TROJAN,
    ProtocolTypes::HYSTERIA,
];
```

可用类型见 `Plugin\CoreProtocols\ProtocolTypes`（如 `ProtocolTypes::VMESS`）。第三方协议使用自身插件定义的 type 字符串。

### `$protocolRequirements` (protected)

基于客户端版本的兼容性过滤配置。格式：`client.server_type.field => version_map`。

```php
protected $protocolRequirements = [
    // 全局规则（适用于所有客户端）
    '*.vless.protocol_settings.network' => [
        'whitelist' => ['tcp', 'ws', 'grpc'],
        'strict' => true,
    ],
    // 特定客户端最低版本
    'verge.hysteria.protocol_settings.version' => [2 => '1.3.8'],
    // 协议类型的基础版本要求
    'stash.tuic.base_version' => '2.3.0',
];
```

## 📥 构造参数

`AbstractProtocol` 构造器接收：

| 参数 | 类型 | 说明 |
|------|------|------|
| `$user` | array | 当前用户信息 (id, email, u, d, transfer_enable, expired_at 等) |
| `$servers` | array | 过滤后的服务器列表 |
| `$clientName` | string\|null | 检测到的客户端名称 (如 `clash`、`verge`) |
| `$clientVersion` | string\|null | 客户端版本号 |
| `$userAgent` | string\|null | 原始 User-Agent 头 |

通过 `$this->user`、`$this->servers`、`$this->clientName`、`$this->clientVersion`、`$this->userAgent` 访问。

## 📤 handle() 返回值

返回 Laravel response 对象：

```php
// YAML/JSON 响应
return response($yamlContent)
    ->header('content-type', 'text/yaml')
    ->header('subscription-userinfo', "upload={$upload}; download={$download}; total={$total}; expire={$expire}");

// 纯文本 URI 响应
return response(base64_encode($uri))
    ->header('content-type', 'text/plain')
    ->header('subscription-userinfo', "upload={$u}; download={$d}; total={$total}; expire={$expire}");

// JSON 响应（SIP008、Sing-box 等）
return response()->json($config)
    ->header('subscription-userinfo', "...");
```

## 🧩 可用辅助方法

`AbstractProtocol` 基类提供：

### `filterByAllowedProtocols()`
自动调用；移除不在 `$allowedProtocols` 中的服务器类型。

### `filterServersByVersion()`
自动调用；根据 `$protocolRequirements` 移除与客户端版本不兼容的服务器。

### `isCompatible(array $server)`
检查单个服务器是否与当前客户端兼容。

### `supportsFeature(string $clientName, string $minVersion, array $additionalConditions = [])`
检查当前客户端是否支持特定功能。

## 🪝 可用钩子

协议插件可使用以下钩子进行深度集成：

| 钩子 | 类型 | 说明 |
|------|------|------|
| `protocols.register` | filter | 注册协议类 |
| `protocol.servers.filtered` | filter | 修改 `handle()` 接收前的已过滤服务器列表 |
| `client.subscribe.before` | action | 订阅生成前触发 |
| `client.subscribe.servers` | filter | 在服务器进入协议前修改 |
| `client.subscribe.unavailable` | action | 用户订阅不可用时触发 |

## 📝 完整示例：简单协议插件

### 目录结构
```
plugins-core/SimpleProtocol/
├── config.json
├── Plugin.php
└── SimpleProtocol.php
```

### config.json
```json
{
    "name": "Simple Protocol",
    "code": "simple_protocol",
    "type": "protocol",
    "version": "1.0.0",
    "description": "生成自定义 URI 格式",
    "author": "Developer"
}
```

### Plugin.php
```php
<?php

namespace Plugin\SimpleProtocol;

use App\Services\Plugin\AbstractPlugin;
use App\Support\AbstractProtocol;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('protocols.register', function ($protocols) {
            if (is_subclass_of(SimpleProtocol::class, AbstractProtocol::class)) {
                $protocols[] = SimpleProtocol::class;
            }
            return $protocols;
        });
    }
}
```

### SimpleProtocol.php
```php
<?php

namespace Plugin\SimpleProtocol;

use App\Support\AbstractProtocol;
use Plugin\CoreProtocols\ProtocolTypes;

class SimpleProtocol extends AbstractProtocol
{
    public $flags = ['simpleapp', 'simple-client'];

    public $allowedProtocols = [
        ProtocolTypes::VMESS,
        ProtocolTypes::VLESS,
        ProtocolTypes::TROJAN,
    ];

    public function handle()
    {
        $lines = [];

        foreach ($this->servers as $server) {
            $lines[] = sprintf(
                '%s://%s@%s:%d#%s',
                $server['type'],
                $server['password'],
                $server['host'],
                $server['port'],
                rawurlencode($server['name'])
            );
        }

        $body = implode("\n", $lines) . "\n";

        return response(base64_encode($body))
            ->header('content-type', 'text/plain')
            ->header('subscription-userinfo',
                "upload={$this->user['u']}; download={$this->user['d']}; " .
                "total={$this->user['transfer_enable']}; expire={$this->user['expired_at']}");
    }
}
```

## 🔄 完整生命周期

1. **发现**：`ProtocolManager` 扫描 `plugins-core/` 和 `plugins/`，查找 `config.json` 中 `type: "protocol"` 的目录
2. **注册**：每个协议类被加载并加入协议池
3. **匹配**：订阅请求到达时，根据 User-Agent 匹配协议标识
4. **实例化**：使用用户信息、服务器列表和客户端信息创建协议实例
5. **执行**：调用 `handle()` 方法，返回订阅响应

## 💡 开发提示

- 保持 `Plugin.php` 精简 — 只做协议注册
- 使用 `App\Utils\Helper` 进行 DNS 解析、IPv6 包装、TLS 指纹识别
- 使用 `subscribe_template('your_flag')` 获取数据库中可自定义的 YAML/JSON 模板
- 使用 `admin_setting('key', 'default')` 获取面板级配置
- YAML 输出使用 `Symfony\Component\Yaml\Yaml::dump()`
- 协议插件支持与普通插件相同的启用/禁用生命周期

---

# 第二部分：协议类型定义插件

协议类型定义插件用于向系统注册新的代理协议类型，包括其在管理后台显示的配置字段、验证规则和前端表单渲染方式。安装后即可在添加/编辑服务器时选择该协议类型。

## 📦 插件结构

```
plugins-core/
└── MyProtocolType/          # 目录名 = 大驼峰
    ├── config.json          # 插件元信息 (必需)
    └── Plugin.php           # 插件引导 (必需)
```

> 协议类型定义插件不需要实现 `AbstractProtocol` 子类。配置字段在 `Plugin.php` 中声明即可。

## 📄 文件规范

### 1. config.json

```json
{
    "name": "My Protocol Type",
    "code": "my_protocol_type",
    "type": "protocol",
    "version": "1.0.0",
    "description": "我的自定义协议类型",
    "author": "Developer"
}
```

| 字段 | 说明 |
|------|------|
| `type` | 必须为 `"protocol"` |
| `code` | 小写+下划线，在数据库中唯一标识此插件 |
| `version` | 语义化版本 |

### 2. Plugin.php

在 `boot()` 方法中调用 `$this->registerProtocolDefinition()` 注册协议类型。

```php
<?php

namespace Plugin\MyProtocolType;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->registerProtocolDefinition(
            type: 'my_protocol',        // 协议类型标识（数据库 type 字段）
            name: 'My Protocol',        // 显示名称
            configFields: [             // 配置字段定义
                'field_name' => [
                    'type' => 'string',
                    'default' => null,
                    'label' => '字段标签',
                ],
            ],
            validationRules: [           // Laravel 验证规则
                'field_name' => 'required|string',
            ],
            // 可选：节点下发配置构建器（ServerService::buildNodeConfig 调用）
            // serverConfigBuilder: fn (Server $node, array $baseConfig) => [...$baseConfig, 'field_name' => ...],
        );
    }
}
```

### 参数说明

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `$type` | string | 是 | 协议类型标识，如 `'my_protocol'`，对应数据库 `type` 字段 |
| `$name` | string | 是 | 管理后台显示的名称 |
| `$configFields` | array | 是 | 配置字段定义，完整格式见下方 |
| `$validationRules` | array | 否 | Laravel 验证规则数组 |
| `$prefix` | string\|array\|null | 否 | 服务器名称前缀，如 `'[ss]'`，或版本映射 `[1=>'[Hy]', 2=>'[Hy2]']` |
| `$serverConfigBuilder` | callable\|null | 否 | 节点配置构建器 `fn(Server $node, array $baseConfig): array`，由 `buildNodeConfig` 调用 |
| `$passwordGenerator` | callable\|null | 否 | 用户密码生成器 `fn(Server $node, User $user): string`（默认 `$user->uuid`） |
| `$aliases` | list\<string\> | 否 | 类型别名（如 `['hysteria2']`），`Server::normalizeType` 会映射到正式 type |
| `$transformNodeUserUuid` | bool | 否 | 为 true 时节点用户列表的 uuid 字段用 passwordGenerator 覆盖（如 Sudoku） |
| `$serverKeyResolver` | callable\|null | 否 | 订阅 `server_key` 访问器 `fn(Server $node): mixed` |

## 🔧 配置字段格式 (`configFields`)

每个字段是一个关联数组，支持以下键：

| 键 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | string | 是 | 字段值类型：`string` / `integer` / `number` / `boolean` / `array` / `object` |
| `default` | mixed | 否 | 默认值，写入数据库时自动应用 |
| `label` | string | 否 | 管理后台显示的字段标签 |
| `description` | string | 否 | 字段说明文字（显示在标签下方） |
| `placeholder` | string | 否 | 输入框占位提示 |
| `options` | object | 否 | 选项列表，存在时渲染为下拉选择框 |
| `fields` | object | 仅 `type=object` | 嵌套子字段定义 |
| `show_when` | object | 否 | 条件显示规则 |

### 字段类型与渲染方式

| `type` 值 | 前端渲染组件 | 说明 |
|-----------|-------------|------|
| `string` | 文本框 / 下拉框 | 如果同时有 `options` 则渲染为下拉框 |
| `integer` | 数字输入框 / 下拉框 | 如果同时有 `options` 则渲染为下拉框 |
| `number` | 数字输入框 | — |
| `boolean` | 开关 (Switch) | — |
| `array` | 多选组件 | 仅当同时有 `options` 时渲染为多选 |
| `object` | 嵌套区域 | 必须同时提供 `fields` 定义子字段 |

### 示例：不同字段类型

```php
'configFields' => [
    // 文本框
    'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],

    // 下拉选择框
    'mode' => ['type' => 'string', 'default' => 'auto', 'label' => '模式',
        'options' => ['auto' => '自动', 'manual' => '手动']],

    // 开关
    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => '启用'],

    // 多选
    'alpn' => ['type' => 'array', 'default' => ['h3'], 'label' => 'ALPN',
        'options' => ['h3' => 'HTTP/3', 'h2' => 'HTTP/2', 'http/1.1' => 'HTTP/1.1']],

    // 嵌套对象
    'tls' => ['type' => 'object', 'fields' => [
        'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
        'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全'],
    ], 'label' => 'TLS 设置'],
],
```

### 条件显示 (`show_when`)

`show_when` 让字段仅在另一个字段为特定值时显示。格式为 `{'依赖字段名': '期望值'}`。

```php
'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
    // 仅在 tls=1 时显示
]],
```

支持多层嵌套：多个条件同时满足时才会显示（AND 逻辑）。

### `options` 的高级用法

当字段有 `options` 时，根据 `type` 不同渲染为不同组件：

| `type` | 渲染组件 | 说明 |
|--------|---------|------|
| `string` / `integer` | 下拉框 (Select) | 单选 |
| `array` | 多选 (MultiSelect) | 多选，选中项显示为标签 |

```php
// 单选用法 (type=string 或 type=integer)
'tls' => ['type' => 'integer', 'options' => ['0' => '关闭', '1' => 'TLS', '2' => 'Reality']],

// 多选用法 (type=array)
'alpn' => ['type' => 'array', 'options' => ['h3' => 'HTTP/3', 'h2' => 'HTTP/2']],
```

## ✅ 验证规则 (`validationRules`)

验证规则使用 Laravel 验证语法，键为字段路径，值为规则字符串。嵌套字段使用点号分隔：

```php
'validationRules' => [
    'tls' => 'required|integer|in:0,1,2',
    'server_name' => 'nullable|string',
    'tls_settings.server_name' => 'nullable|string',
    'multiplex.enabled' => 'nullable|boolean',
    'multiplex.protocol' => 'nullable|string|in:yamux,h2mux,smux',
    'alpn' => 'nullable|array',
],
```

## 📝 完整示例

### 目录结构
```
plugins-core/MyProtocolType/
├── config.json
└── Plugin.php
```

### config.json
```json
{
    "name": "My Protocol Type",
    "code": "my_protocol_type",
    "type": "protocol",
    "version": "1.0.0",
    "description": "示例自定义协议类型，包含各种配置字段",
    "author": "Developer"
}
```

### Plugin.php
```php
<?php

namespace Plugin\MyProtocolType;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->registerProtocolDefinition(
            type: 'my_protocol',
            name: 'My Protocol',
            configFields: [
                'mode' => ['type' => 'string', 'default' => 'auto', 'label' => '模式',
                    'options' => ['auto' => '自动', 'manual' => '手动']],
                'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS',
                    'options' => ['0' => '关闭', '1' => '开启']],
                'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                    'server_name' => ['type' => 'string', 'default' => null, 'label' => '服务器名称'],
                    'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => '允许不安全'],
                ], 'label' => 'TLS设置'],
                'alpn' => ['type' => 'array', 'default' => ['h3'], 'label' => 'ALPN',
                    'options' => ['h3' => 'HTTP/3', 'h2' => 'HTTP/2']],
                'hop_interval' => ['type' => 'integer', 'default' => null, 'label' => '跳跃间隔'],
            ],
            validationRules: [
                'mode' => 'required|string|in:auto,manual',
                'tls' => 'required|integer|in:0,1',
                'tls_settings.server_name' => 'nullable|string',
                'tls_settings.allow_insecure' => 'nullable|boolean',
                'alpn' => 'nullable|array',
                'hop_interval' => 'nullable|integer',
            ],
        );
    }
}
```

## 🔄 注册与发现机制

1. **自动安装**：系统启动时扫描 `plugins-core/` 目录，找到 `type: "protocol"` 的插件自动安装并启用
2. **钩子注册**：`registerProtocolDefinition()` 内部通过 `protocols.definitions` 钩子注册
3. **合并加载**：`ProtocolDefinitionRegistry` 收集所有插件注册的定义，合并后供系统使用
4. **API 暴露**：`ProtocolDefinitionController` 提供 REST API 供管理后台前端动态获取

## 🔧 节点配置构建（`serverConfigBuilder`）

`ServerService::buildNodeConfig()` 不再硬编码各协议字段，而是：

1. 组装公共 `$baseConfig`（protocol / listen_ip / server_port / network / networkSettings / maintenance_mode）
2. 从 `ProtocolDefinitionRegistry` 取对应协议的 `serverConfigBuilder` 生成协议专属字段
3. 追加 routes / custom_outbounds / custom_routes / cert_config
4. 经 `protocols.server_config` 钩子允许其它插件再扩展

注册时传入 builder：

```php
$this->registerProtocolDefinition(
    type: 'my_protocol',
    name: 'My Protocol',
    configFields: [...],
    validationRules: [...],
    prefix: '[my]',
    serverConfigBuilder: function (\App\Models\Server $node, array $baseConfig): array {
        $settings = $node->protocol_settings;
        return [
            ...$baseConfig,
            'server_port' => (int) $node->server_port,
            'custom_field' => $settings['custom_field'] ?? null,
        ];
    },
);
```

CoreProtocols 内建协议的 builder 见 `plugins-core/CoreProtocols/NodeConfigBuilders.php`。

## 🔧 钩子扩展

插件可以通过以下钩子扩展或覆盖协议类型定义：

| 钩子 | 类型 | 说明 |
|------|------|------|
| `protocols.definitions` | filter | 注册/修改协议类型定义 |
| `protocols.server_config` | filter | 在生成节点配置后修改完整配置数组 |

### 使用 `protocols.server_config` 钩子

当需要在已构建的节点配置上追加/覆盖字段时：

```php
public function boot(): void
{
    // 注册协议类型（含 serverConfigBuilder 更推荐）
    $this->registerProtocolDefinition(...);

    // 在完整配置上追加字段
    $this->filter('protocols.server_config', function (array $config, $node) {
        $type = $node->type;
        $settings = $node->protocol_settings;

        if ($type === 'my_protocol') {
            $config['custom_field'] = $settings['custom_field'] ?? null;
        }

        return $config;
    }, 20);
}
```

## 📌 注意事项

- 协议类型标识 `$type` 必须全局唯一，不能与已有类型（`shadowsocks`、`vmess`、`vless`、`trojan`、`hysteria`、`tuic`、`anytls`、`socks`、`naive`、`http`、`mieru`）重名
- `$configFields` 中的字段名会存入数据库 `protocol_settings` JSON 字段，命名建议使用小写+下划线
- `options` 和 `show_when` 是前端元数据，`castSettingsWithConfig` 方法会忽略这些键
- 验证规则更新后需要重新启用插件或清除缓存才能生效
