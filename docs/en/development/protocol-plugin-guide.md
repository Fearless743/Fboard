# Protocol Plugin Development Guide

Protocol plugins convert internal server configurations into client-readable subscription formats (YAML, JSON, URI, etc.). Each protocol plugin runs independently and is auto-discovered by the system.

> **Note**: XBoard has two types of "protocol plugins":
> 1. **Output format plugins** (first half of this guide) — Convert server configs to client subscription formats like Clash, SingBox
> 2. **Protocol type definition plugins** (second half) — Define supported protocol types and their configuration fields like Shadowsocks, VMess

---

# Part 1: Output Format Plugins

## 📦 Plugin Structure

```
plugins-core/
└── YourProtocol/            # Directory name = StudlyCase (e.g., ClashMeta, SingBox)
    ├── config.json          # Plugin metadata (required)
    ├── Plugin.php           # Plugin bootstrap (required)
    └── YourProtocol.php     # Protocol implementation (required)
```

Can also be placed in `plugins/` (user-installed) with the same structure.

## 📄 File Specifications

### 1. config.json

```json
{
    "name": "Your Protocol Name",
    "code": "your_protocol",
    "type": "protocol",
    "version": "1.0.0",
    "description": "What this protocol does",
    "author": "Your Name"
}
```

| Field | Description |
|-------|-------------|
| `type` | Must be `"protocol"` |
| `code` | Lowercase + underscore, used for DB installation |
| `version` | Semver format (`\d+.\d+.\d+`) |

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

The `boot()` method registers the protocol class via the `protocols.register` hook. The `Plugin.php` is only needed for DB lifecycle (install/enable/disable). Protocol discovery also works via filesystem scanning in `ProtocolManager`.

### 3. YourProtocol.php

Your protocol class must extend `App\Support\AbstractProtocol` and implement the `handle()` method.

```php
<?php

namespace Plugin\YourProtocol;

use App\Support\AbstractProtocol;
use App\Models\Server;

class YourProtocol extends AbstractProtocol
{
    public $flags = ['your_client_flag1', 'flag2'];

    public $allowedProtocols = [
        Server::TYPE_VMESS,
        Server::TYPE_SHADOWSOCKS,
    ];

    protected $protocolRequirements = [];

    public function handle()
    {
        // Convert $this->servers to client format
        // Return a response object
    }
}
```

## 🔧 Core Properties

### `$flags` (public)

Array of User-Agent strings that trigger this protocol. The system matches these flags against the client's User-Agent header or `?flag=` parameter.

```php
public $flags = ['clash', 'verge', 'nekobox'];
```

Matching is case-insensitive substring match. Protocols are checked in reverse order of discovery, so later-discovered protocols (user plugins) can override earlier ones (core plugins).

### `$allowedProtocols` (public)

White-list of server types this protocol supports. Servers of other types are filtered out before `handle()` is called.

```php
public $allowedProtocols = [
    Server::TYPE_VMESS,
    Server::TYPE_TROJAN,
    Server::TYPE_HYSTERIA,
];
```

Available types: `TYPE_VMESS`, `TYPE_VLESS`, `TYPE_SHADOWSOCKS`, `TYPE_TROJAN`, `TYPE_HYSTERIA`, `TYPE_TUIC`, `TYPE_ANYTLS`, `TYPE_SOCKS`, `TYPE_NAIVE`, `TYPE_HTTP`, `TYPE_MIERU`.

### `$protocolRequirements` (protected)

Version-based compatibility filtering. Format: `client.server_type.field => version_map`.

```php
protected $protocolRequirements = [
    // Global rules (apply to all clients)
    '*.vless.protocol_settings.network' => [
        'whitelist' => ['tcp', 'ws', 'grpc'],
        'strict' => true,
    ],
    // Client-specific minimum version
    'verge.hysteria.protocol_settings.version' => [2 => '1.3.8'],
    // Base version requirement for a protocol type
    'stash.tuic.base_version' => '2.3.0',
];
```

## 📥 Constructor Parameters

The `AbstractProtocol` constructor receives:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user` | array | Current user info (id, email, u, d, transfer_enable, expired_at, etc.) |
| `$servers` | array | Filtered server list |
| `$clientName` | string\|null | Detected client name (e.g., `clash`, `verge`) |
| `$clientVersion` | string\|null | Client version string |
| `$userAgent` | string\|null | Raw User-Agent header |

Accessible via `$this->user`, `$this->servers`, `$this->clientName`, `$this->clientVersion`, `$this->userAgent`.

## 📤 handle() Return Value

Return a Laravel response object:

```php
// YAML/JSON response
return response($yamlContent)
    ->header('content-type', 'text/yaml')
    ->header('subscription-userinfo', "upload={$upload}; download={$download}; total={$total}; expire={$expire}");

// Plain text URI response
return response(base64_encode($uri))
    ->header('content-type', 'text/plain')
    ->header('subscription-userinfo', "upload={$u}; download={$d}; total={$total}; expire={$expire}");

// JSON response (for SIP008, Sing-box, etc.)
return response()->json($config)
    ->header('subscription-userinfo', "...");
```

## 🧩 Available Helper Methods

The `AbstractProtocol` base class provides:

### `filterByAllowedProtocols()`
Automatically called; removes server types not in `$allowedProtocols`.

### `filterServersByVersion()`
Automatically called; removes servers incompatible with client version based on `$protocolRequirements`.

### `isCompatible(array $server)`
Check if a single server is compatible with the current client.

### `supportsFeature(string $clientName, string $minVersion, array $additionalConditions = [])`
Check if the current client supports a specific feature.

## 🪝 Available Hooks

Protocol plugins can use these hooks for deeper integration:

| Hook | Type | Description |
|------|------|-------------|
| `protocols.register` | filter | Register a protocol class |
| `protocol.servers.filtered` | filter | Modify the filtered server list before `handle()` |
| `client.subscribe.before` | action | Before subscription generation |
| `client.subscribe.servers` | filter | Modify servers before they reach the protocol |
| `client.subscribe.unavailable` | action | When user subscription is unavailable |

## 📝 Complete Example: Simple Protocol Plugin

### Directory structure
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
    "description": "Generates custom URI format",
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
use App\Models\Server;

class SimpleProtocol extends AbstractProtocol
{
    public $flags = ['simpleapp', 'simple-client'];

    public $allowedProtocols = [
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_TROJAN,
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

## 🔄 Full Lifecycle

1. **Discovery**: `ProtocolManager` scans `plugins-core/` and `plugins/` for directories with `type: "protocol"` in `config.json`
2. **Registration**: Each proto col class is loaded and added to the protocol pool
3. **Matching**: When a subscription request arrives, flags are matched against the User-Agent
4. **Instantiation**: The protocol class is created with user, servers, and client info
5. **Execution**: `handle()` is called, returning the subscription response

## 💡 Tips

- Keep `Plugin.php` minimal — just register the protocol class
- Use `App\Utils\Helper` for DNS resolution, IPv6 wrapping, TLS fingerprinting
- Use `subscribe_template('your_flag')` for customizable YAML/JSON templates stored in the database
- Use `admin_setting('key', 'default')` for panel-wide configuration
- For YAML output, use `Symfony\Component\Yaml\Yaml::dump()`
- Test your protocol with `vendor/bin/phpunit` if tests exist
- Protocol plugins respect the same enable/disable lifecycle as regular plugins

---

# Part 2: Protocol Type Definition Plugins

Protocol type definition plugins register new proxy protocol types into the system, including their configuration fields, validation rules, and frontend form rendering. Once installed, the new protocol type becomes available in the server management form.

## 📦 Plugin Structure

```
plugins-core/
└── MyProtocolType/          # Directory name = StudlyCase
    ├── config.json          # Plugin metadata (required)
    └── Plugin.php           # Plugin bootstrap (required)
```

> Protocol type definition plugins do NOT need to implement `AbstractProtocol`. Configuration fields are declared directly in `Plugin.php`.

## 📄 File Specifications

### 1. config.json

```json
{
    "name": "My Protocol Type",
    "code": "my_protocol_type",
    "type": "protocol",
    "version": "1.0.0",
    "description": "My custom protocol type",
    "author": "Developer"
}
```

| Field | Description |
|-------|-------------|
| `type` | Must be `"protocol"` |
| `code` | Lowercase + underscore, unique DB identifier |
| `version` | Semver format |

### 2. Plugin.php

Call `$this->registerProtocolDefinition()` in the `boot()` method:

```php
<?php

namespace Plugin\MyProtocolType;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->registerProtocolDefinition(
            type: 'my_protocol',        // Protocol type identifier (DB type column)
            name: 'My Protocol',        // Display name
            configFields: [             // Configuration fields
                'field_name' => [
                    'type' => 'string',
                    'default' => null,
                    'label' => 'Field Label',
                ],
            ],
            validationRules: [           // Laravel validation rules
                'field_name' => 'required|string',
            ],
        );
    }
}
```

### Parameter Reference

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$type` | string | yes | Protocol type identifier, e.g. `'my_protocol'`, maps to DB `type` column |
| `$name` | string | yes | Display name in admin panel |
| `$configFields` | array | yes | Field definitions, see full format below |
| `$validationRules` | array | no | Laravel validation rule array |

## 🔧 Config Field Format (`configFields`)

Each field is an associative array supporting these keys:

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `type` | string | yes | Value type: `string` / `integer` / `number` / `boolean` / `array` / `object` |
| `default` | mixed | no | Default value, auto-applied when writing to DB |
| `label` | string | no | Field label shown in admin form |
| `description` | string | no | Help text shown below the label |
| `placeholder` | string | no | Input placeholder text |
| `options` | object | no | Option list; when present, field renders as a dropdown |
| `fields` | object | only if `type=object` | Nested sub-field definitions |
| `show_when` | object | no | Conditional display rules |

### Type & Rendering

| `type` value | Frontend Component | Notes |
|-------------|-------------------|-------|
| `string` | Text input / Select | Renders as Select when `options` is present |
| `integer` | Number input / Select | Renders as Select when `options` is present |
| `number` | Number input | — |
| `boolean` | Switch | — |
| `array` | Multi-select | Renders as multi-select when `options` is present |
| `object` | Nested field group | Requires `fields` for sub-field definitions |

### Field Examples

```php
'configFields' => [
    // Text input
    'server_name' => ['type' => 'string', 'default' => null, 'label' => 'Server Name'],

    // Dropdown select
    'mode' => ['type' => 'string', 'default' => 'auto', 'label' => 'Mode',
        'options' => ['auto' => 'Auto', 'manual' => 'Manual']],

    // Switch toggle
    'enabled' => ['type' => 'boolean', 'default' => false, 'label' => 'Enabled'],

    // Multi-select
    'alpn' => ['type' => 'array', 'default' => ['h3'], 'label' => 'ALPN',
        'options' => ['h3' => 'HTTP/3', 'h2' => 'HTTP/2', 'http/1.1' => 'HTTP/1.1']],

    // Nested object
    'tls' => ['type' => 'object', 'fields' => [
        'server_name' => ['type' => 'string', 'default' => null, 'label' => 'Server Name'],
        'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => 'Allow Insecure'],
    ], 'label' => 'TLS Settings'],
],
```

### Conditional Display (`show_when`)

`show_when` hides a field unless another field has a specific value. Format: `{'dependency_field': 'expected_value'}`.

```php
'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
    // Only visible when tls=1
]],
```

Multiple conditions: all must be satisfied (AND logic).

### Options Advanced Usage

When `options` is present on a field, the rendering component depends on `type`:

| `type` | Component | Selection |
|--------|-----------|-----------|
| `string` / `integer` | Select | Single |
| `array` | MultiSelect | Multiple (shown as tags) |

```php
// Single select (type=string or type=integer)
'tls' => ['type' => 'integer', 'options' => ['0' => 'Off', '1' => 'TLS', '2' => 'Reality']],

// Multi select (type=array)
'alpn' => ['type' => 'array', 'options' => ['h3' => 'HTTP/3', 'h2' => 'HTTP/2']],
```

## ✅ Validation Rules (`validationRules`)

Uses Laravel validation syntax. Keys are field paths, values are rule strings. Use dot notation for nested fields:

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

## 📝 Complete Example

### Directory structure
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
    "description": "Example custom protocol with various field types",
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
                'mode' => ['type' => 'string', 'default' => 'auto', 'label' => 'Mode',
                    'options' => ['auto' => 'Auto', 'manual' => 'Manual']],
                'tls' => ['type' => 'integer', 'default' => 0, 'label' => 'TLS',
                    'options' => ['0' => 'Off', '1' => 'On']],
                'tls_settings' => ['type' => 'object', 'show_when' => ['tls' => '1'], 'fields' => [
                    'server_name' => ['type' => 'string', 'default' => null, 'label' => 'Server Name'],
                    'allow_insecure' => ['type' => 'boolean', 'default' => false, 'label' => 'Allow Insecure'],
                ], 'label' => 'TLS Settings'],
                'alpn' => ['type' => 'array', 'default' => ['h3'], 'label' => 'ALPN',
                    'options' => ['h3' => 'HTTP/3', 'h2' => 'HTTP/2']],
                'hop_interval' => ['type' => 'integer', 'default' => null, 'label' => 'Hop Interval'],
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

## 🔄 Registration & Discovery

1. **Auto-install**: On system boot, scans `plugins-core/` for `type: "protocol"` plugins and auto-installs+enables them
2. **Hook registration**: `registerProtocolDefinition()` internally registers via the `protocols.definitions` hook
3. **Merge loading**: `ProtocolDefinitionRegistry` collects definitions from all plugins and merges them
4. **API exposure**: `ProtocolDefinitionController` provides REST API for the admin frontend

## 🔧 Extension Hooks

Plugins can extend or override protocol type definitions with these hooks:

| Hook | Type | Description |
|------|------|-------------|
| `protocols.definitions` | filter | Register/modify protocol type definitions |
| `protocols.server_config` | filter | Modify server config array during node config generation |

### Using `protocols.server_config`

To customize node config output (e.g., add custom fields to subscription config):

```php
public function boot(): void
{
    // Register protocol type
    $this->registerProtocolDefinition(...);

    // Custom config builder
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

## 📌 Notes

- The `$type` identifier must be globally unique. Do not conflict with existing types: `shadowsocks`, `vmess`, `vless`, `trojan`, `hysteria`, `tuic`, `anytls`, `socks`, `naive`, `http`, `mieru`
- Field names in `$configFields` are stored in the `protocol_settings` JSON column. Use lowercase+underscore naming
- `options` and `show_when` are frontend metadata; `castSettingsWithConfig()` strips these keys automatically
- After updating validation rules, re-enable the plugin or clear the cache for changes to take effect
