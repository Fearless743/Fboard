# XBoard Plugin Development Guide

## 📦 Plugin Structure

Each plugin is an independent directory with the following structure:

```
plugins/
└── YourPlugin/               # Plugin directory (PascalCase naming)
    ├── Plugin.php           # Main plugin class (required)
    ├── config.json          # Plugin configuration (required)
    ├── routes/
    │   └── api.php          # API routes
    ├── Controllers/         # Controllers directory
    │   └── YourController.php
    ├── Commands/            # Artisan commands directory
    │   └── YourCommand.php
    └── README.md            # Documentation
```

## 🚀 Quick Start

### 1. Create Configuration File `config.json`

```json
{
    "name": "My Plugin",
    "code": "my_plugin", // Corresponds to plugin directory (lowercase + underscore)
    "version": "1.0.0",
    "description": "Plugin functionality description",
    "author": "Author Name",
    "require": {
        "xboard": ">=1.0.0" // Version not fully implemented yet
    },
    "config": {
        "api_key": {
            "type": "string",
            "default": "",
            "label": "API Key",
            "description": "API Key"
        },
        "timeout": {
            "type": "number",
            "default": 300,
            "label": "Timeout (seconds)",
            "description": "Timeout in seconds"
        }
    }
}
```

### 2. Create Main Plugin Class `Plugin.php`

```php
<?php

namespace Plugin\YourPlugin;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    /**
     * Called when plugin starts
     */
    public function boot(): void
    {
        // Register frontend configuration hook
        $this->filter('guest_comm_config', function ($config) {
            $config['my_plugin_enable'] = true;
            $config['my_plugin_setting'] = $this->getConfig('api_key', '');
            return $config;
        });
    }
}
```

### 3. Create Controller

**Recommended approach: Extend PluginController**

```php
<?php

namespace Plugin\YourPlugin\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\Request;

class YourController extends PluginController
{
    public function handle(Request $request)
    {
        // Get plugin configuration
        $apiKey = $this->getConfig('api_key');
        $timeout = $this->getConfig('timeout', 300);

        // Your business logic...

        return $this->success(['message' => 'Success']);
    }
}
```

### 4. Create Routes `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use Plugin\YourPlugin\Controllers\YourController;

Route::group([
    'prefix' => 'api/v1/your-plugin'
], function () {
    Route::post('/handle', [YourController::class, 'handle']);
});
```

## 🔧 Configuration Access

In controllers, you can easily access plugin configuration:

```php
// Get single configuration
$value = $this->getConfig('key', 'default_value');

// Get all configurations
$allConfig = $this->getConfig();

// Check if plugin is enabled
$enabled = $this->isPluginEnabled();
```

## 🎣 Hook System

### Popular Hooks (Recommended to follow)

XBoard has built-in hooks for many business-critical nodes. Plugin developers can flexibly extend through `filter` or `listen` methods. Here are the most commonly used and valuable hooks:

| Hook Name | Type | Typical Parameters | Description |
|-----------|------|-------------------|-------------|
| **👤 User** | | | |
| user.register.before | action | Request | Before user registration |
| user.register.after | action | User | After user registration |
| user.login.after | action | User | After user login |
| user.password.reset.after | action | User | After password reset |
| user.change_password.after | action | User, Request | After user changes password |
| user.update.before | action | User, params, Request | Before user updates settings |
| user.update.after | action | User, params, Request | After user updates settings |
| user.reset_security.after | action | User, old_uuid, old_token, Request | After user resets subscription info |
| user.transfer.after | action | User, amount, Request | After user transfers commission to balance |
| user.info.response | filter | User, Request | Modify user info API response |
| user.subscribe.response | filter | User | Modify user subscription response |
| user.knowledge.resource | filter | data, request, resource | Modify knowledge resource output |
| **📋 Admin - User Management** | | | |
| admin.user.secret.reset | action | user, request | After admin resets user secret |
| admin.user.fetch.query | filter | query, request | Modify admin user list query |
| admin.user.transform | filter | user, model | Modify admin user list item |
| admin.user.detail | filter | user, request | Modify admin user detail |
| admin.user.update.params | filter | params, request, user | Modify admin update user params |
| admin.user.update.before | action | user, params, request | Before admin updates user |
| admin.user.update.after | action | user, params, request | After admin updates user |
| admin.user.update.rules | filter | rules, request | Modify admin user update validation rules |
| admin.user.update.messages | filter | messages, request | Modify admin user update validation messages |
| admin.user.destroy.before | action | user, request | Before admin deletes user |
| admin.user.destroy.after | action | user, request | After admin deletes user |
| **📦 Admin - Plan Management** | | | |
| admin.plan.save.before | action | plan, params, request | Before creating/updating plan |
| admin.plan.save.after | action | plan, params, request | After creating/updating plan |
| admin.plan.update.before | action | plan, params, request | Before toggling plan show/renew/sell |
| admin.plan.update.after | action | plan, params, request | After toggling plan show/renew/sell |
| admin.plan.drop.before | action | plan, request | Before deleting plan |
| admin.plan.drop.after | action | plan, request | After deleting plan |
| admin.plan.sort.before | action | params, request | Before sorting plans |
| admin.plan.sort.after | action | params, request | After sorting plans |
| **🖥️ Admin - Server Management** | | | |
| admin.server.save.before | action | server, params, request | Before creating/updating server |
| admin.server.save.after | action | server, params, request | After creating/updating server |
| admin.server.update.before | action | server, params, request | Before toggling server show/enabled |
| admin.server.update.after | action | server, params, request | After toggling server show/enabled |
| admin.server.drop.before | action | server, request | Before deleting server |
| admin.server.drop.after | action | server, request | After deleting server |
| admin.server.sort.before | action | params, request | Before sorting servers |
| admin.server.sort.after | action | params, request | After sorting servers |
| admin.server.batch_delete.before | action | ids, request | Before batch deleting servers |
| admin.server.batch_delete.after | action | ids, request | After batch deleting servers |
| admin.server.batch_update.before | action | ids, update, request | Before batch updating servers |
| admin.server.batch_update.after | action | ids, update, request | After batch updating servers |
| **💰 Admin - Coupon Management** | | | |
| admin.coupon.update.before | action | params, request | Before updating coupon |
| admin.coupon.update.after | action | coupon, params, request | After updating coupon |
| admin.coupon.generate.before | action | params/request, request | Before generating coupon(s) |
| admin.coupon.generate.after | action | coupon/count, params, request | After generating coupon(s) |
| admin.coupon.show.toggle | action | coupon, original_show, new_show, request | After toggling coupon show status |
| admin.coupon.drop.before | action | coupon, request | Before deleting coupon |
| admin.coupon.drop.after | action | coupon, request | After deleting coupon |
| **💳 Admin - Payment Management** | | | |
| admin.payment.save.before | action | params, request | Before creating/updating payment |
| admin.payment.save.after | action | payment, params, request | After creating/updating payment |
| admin.payment.show.toggle | action | payment, original_enable, new_enable, request | After toggling payment enable status |
| admin.payment.drop.before | action | payment, request | Before deleting payment |
| admin.payment.drop.after | action | payment, request | After deleting payment |
| admin.payment.sort.before | action | request | Before sorting payments |
| admin.payment.sort.after | action | request | After sorting payments |
| **⚙️ Admin - System Config** | | | |
| admin.config.save.before | action | data, request | Before saving system settings |
| admin.config.save.after | action | data, request | After saving system settings |
| **🎫 Ticket** | | | |
| ticket.create.after | action | Ticket | After ticket creation |
| ticket.reply.user.after | action | Ticket | After user replies to ticket |
| ticket.reply.admin.after | action | Ticket, TicketMessage | After admin replies to ticket |
| admin.ticket.close.before | action | ticket, request | Before admin closes ticket |
| admin.ticket.close.after | action | ticket, request | After admin closes ticket |
| **🛒 Order** | | | |
| order.create.before | action | user, plan, period, coupon | Before order creation |
| order.create.after | action | Order | After order creation |
| order.open.before | action | Order | Before order activation |
| order.open.after | action | Order | After order activation |
| order.paid.before | action | Order | Before marking order as paid |
| order.paid.after | action | Order | After marking order as paid |
| order.cancel.before | action | Order | Before order cancellation |
| order.cancel.after | action | Order | After order cancellation |
| **💵 Payment** | | | |
| payment.notify.before | action | method, uuid, request | Before payment callback |
| payment.notify.verified | action | array | Payment callback verification successful |
| payment.notify.failed | action | method, uuid, request | Payment callback verification failed |
| payment.notify.success | action | Order | After successful payment |
| available_payment_methods | filter | methods | Modify available payment methods |
| **🔗 Subscription** | | | |
| client.auth.before | action | Request | Before client token auth (subscribe middleware) |
| client.auth.token | action | [Request, token] | After token extracted, before user lookup |
| client.auth.after | action | [Request, User] | After client auth succeeds |
| client.subscribe.before | action | Request | Before subscription generation |
| client.subscribe.servers | filter | servers, user, request | Modify servers before protocol processing |
| client.subscribe.unavailable | action | [Request, User] | When subscription is unavailable |
| client.subscribe.after | action | [Request, User, Response] | After successful subscription response |
| subscribe.url | filter | url | Modify subscription URL |
| guest_comm_config | filter | config | Modify guest common config |
| **🔌 Protocol** | | | |
| protocols.register | filter | [] | Register protocol handler classes |
| protocols.definitions | filter | [] | Register/modify protocol type definitions |
| protocols.server_config | filter | [], node | Modify server config for protocol |
| protocol.servers.filtered | filter | servers | Modify filtered servers before handle() |
| **📊 Traffic** | | | |
| traffic.process.before | filter | server, protocol, data | Before traffic data processing |
| traffic.reset.after | action | User | After user traffic reset |
| traffic.batch_reset.before | action | batch_size | Before batch traffic reset |
| traffic.batch_reset.after | action | result | After batch traffic reset |
| **🤖 Telegram** | | | |
| telegram.message.before | action | [msg] | Before processing Telegram message |
| telegram.message.handle | filter | false, [msg] | Handle Telegram message |
| telegram.message.unhandled | action | [msg] | When Telegram message is not handled |
| telegram.message.after | action | [msg] | After processing Telegram message |
| telegram.message.error | action | [msg, exception] | When Telegram message processing errors |
| telegram.bot.commands | filter | [] | Register Telegram bot commands |
| **🎟️ Coupon** | | | |
| coupon.used | action | coupon, order | After coupon is applied to an order |
| **🔑 Server** | | | |
| server.users.get | filter | users, node | Modify node's accessible user list |

### Filter Hooks

Used to modify data:

```php
// In Plugin.php boot() method
$this->filter('guest_comm_config', function ($config) {
    // Add configuration for frontend
    $config['my_setting'] = $this->getConfig('setting');
    return $config;
});
```

### Action Hooks

Used to execute operations:

```php
$this->listen('user.created', function ($user) {
    // Operations after user creation
    $this->doSomething($user);
});
```

### 🤖 Bot Reply Service

Plugins can use the built-in `TicketService::replyByBot()` to automatically reply to tickets. Messages replied this way are marked with `is_bot = true` and the admin panel will display a **Bot** badge next to them.

```php
use App\Services\TicketService;

// In plugin boot() or hook callback
$ticketService = app(TicketService::class);
$ticketService->replyByBot(
    ticketId: $ticketId,
    message: 'Your issue has been automatically resolved.'
);
```

Optionally specify a custom bot user ID (defaults to `0`):

```php
$ticketService->replyByBot(
    ticketId: $ticketId,
    message: '...',
    botUserId: 1
);
```

> 💡 Combine with hooks like `ticket.create.after` or `ticket.reply.user.after` to trigger automatic replies when a ticket is created or a user responds.

### ⚡ Plugin Action Buttons

Plugins can register clickable action buttons that appear in the admin plugin management page. When clicked, the defined callback function is executed. The **admin frontend applies a shared action-result contract** (not plugin-specific), so any plugin can open URLs, copy text, trigger downloads, etc.

```php
public function boot(): void
{
    // 1) Normal action — call handler via API
    $this->registerAction(
        name: 'sync_users',
        label: 'Sync Users',
        handler: function (array $params = []) {
            \Log::info('Syncing users...');
            return [
                'message' => 'Synced 10 users',
                'reload' => true,
            ];
        },
        options: [
            'icon'    => '🔄',
            'confirm' => 'Sync all users?',
            'color'   => 'default', // default | destructive | outline
        ],
    );

    // 2) Link action — open a page (reusable for any plugin admin UI)
    $this->registerAction(
        name: 'open_panel',
        label: 'Open Panel',
        handler: function () {
            return [
                'message' => 'Opened',
                'open_url' => '/plugins/my_plugin/index.html',
                'target' => '_blank',
                'reload' => false,
            ];
        },
        options: [
            'icon' => '🛡',
            'color' => 'outline',
            'type' => 'link',                         // admin may open without waiting
            'url' => '/plugins/my_plugin/index.html', // static URL in action meta
            'target' => '_blank',
        ],
    );
}
```

Parameters:

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Unique action identifier (snake_case) |
| `label` | string | Button text displayed in admin panel |
| `handler` | callable | Callback that receives `array $params` |
| `options.icon` | string | Emoji icon shown on the button |
| `options.confirm` | string|null | Confirmation dialog text; `null` = no confirm |
| `options.color` | string | Button style: `default`, `destructive`, or `outline` |
| `options.type` | string | `default` (run handler) or `link` (open `url`) |
| `options.url` | string | Static URL for `type=link` |
| `options.target` | string | `_blank` (default) or `_self` |

#### Shared handler return / side-effect contract

Any plugin may return these fields; the admin SPA handles them generically:

| Field | Type | Effect |
|-------|------|--------|
| `message` | string | Success toast text |
| `open_url` / `url` | string | Open URL (`target` controls window) |
| `target` | `_blank` \| `_self` | How to open the URL |
| `copy_text` | string | Copy to clipboard |
| `download_url` | string | Trigger download / open file |
| `reload` | bool | Refresh plugin list (default true unless `type=link`) |

> 💡 The action button only appears when the plugin is **enabled**. Prefer `open_url` over hardcoding frontend behavior for a single plugin name.

---

## 📝 Real Example: Telegram Login Plugin

Using TelegramLogin plugin as an example to demonstrate complete implementation:

**Main Plugin Class** (23 lines):

```php
<?php

namespace Plugin\TelegramLogin;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('guest_comm_config', function ($config) {
            $config['telegram_login_enable'] = true;
            $config['telegram_login_domain'] = $this->getConfig('domain', '');
            $config['telegram_bot_username'] = $this->getConfig('bot_username', '');
            return $config;
        });
    }
}
```

**Controller** (extends PluginController):

```php
class TelegramLoginController extends PluginController
{
    public function telegramLogin(Request $request)
    {
        // Check plugin status
        if ($error = $this->beforePluginAction()) {
            return $error[1];
        }

        // Get configuration
        $botToken = $this->getConfig('bot_token');
        $timeout = $this->getConfig('auth_timeout', 300);

        // Business logic...

        return $this->success($result);
    }
}
```

## ⏰ Plugin Scheduled Tasks (Scheduler)

Plugins can register their own scheduled tasks by implementing the `schedule(Schedule $schedule)` method in the main class.

**Example:**

```php
use Illuminate\Console\Scheduling\Schedule;

class Plugin extends AbstractPlugin
{
    public function schedule(Schedule $schedule): void
    {
        // Execute every hour
        $schedule->call(function () {
            // Your scheduled task logic
            \Log::info('Plugin scheduled task executed');
        })->hourly();
    }
}
```

- Just implement the `schedule()` method in Plugin.php.
- All plugin scheduled tasks will be automatically scheduled by the main program.
- Supports all Laravel scheduler usage.

## 🖥️ Plugin Artisan Commands

Plugins can automatically register Artisan commands by creating command classes in the `Commands/` directory.

### Command Directory Structure

```
plugins/YourPlugin/
├── Commands/
│   ├── TestCommand.php      # Test command
│   ├── BackupCommand.php    # Backup command
│   └── CleanupCommand.php   # Cleanup command
```

### Create Command Class

**Example: TestCommand.php**

```php
<?php

namespace Plugin\YourPlugin\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'your-plugin:test {action=ping} {--message=Hello}';
    protected $description = 'Test plugin functionality';

    public function handle(): int
    {
        $action = $this->argument('action');
        $message = $this->option('message');

        try {
            return match ($action) {
                'ping' => $this->ping($message),
                'info' => $this->showInfo(),
                default => $this->showHelp()
            };
        } catch (\Exception $e) {
            $this->error('Operation failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function ping(string $message): int
    {
        $this->info("✅ {$message}");
        return 0;
    }

    protected function showInfo(): int
    {
        $this->info('Plugin Information:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Plugin Name', 'YourPlugin'],
                ['Version', '1.0.0'],
                ['Status', 'Enabled'],
            ]
        );
        return 0;
    }

    protected function showHelp(): int
    {
        $this->info('Usage:');
        $this->line('  php artisan your-plugin:test ping --message="Hello"  # Test');
        $this->line('  php artisan your-plugin:test info                    # Show info');
        return 0;
    }
}
```

### Automatic Command Registration

- ✅ Automatically register all commands in `Commands/` directory when plugin is enabled
- ✅ Command namespace automatically set to `Plugin\YourPlugin\Commands`
- ✅ Supports all Laravel command features (arguments, options, interaction, etc.)

### Usage Examples

```bash
# Test command
php artisan your-plugin:test ping --message="Hello World"

# Show information
php artisan your-plugin:test info

# View help
php artisan your-plugin:test --help
```

### Best Practices

1. **Command Naming**: Use `plugin-name:action` format, e.g., `telegram:test`
2. **Error Handling**: Wrap main logic with try-catch
3. **Return Values**: Return 0 for success, 1 for failure
4. **User Friendly**: Provide clear help information and error messages
5. **Type Declarations**: Use PHP 8.2 type declarations

## 🛠️ Development Tools

### Controller Base Class Selection

**Method 1: Extend PluginController (Recommended)**

- Automatic configuration access: `$this->getConfig()`
- Automatic status checking: `$this->beforePluginAction()`
- Unified error handling

**Method 2: Use HasPluginConfig Trait**

```php
use App\Http\Controllers\Controller;
use App\Traits\HasPluginConfig;

class YourController extends Controller
{
    use HasPluginConfig;

    public function handle()
    {
        $config = $this->getConfig('key');
        // ...
    }
}
```

### Configuration Types

Supported configuration types:

- `string` - Single-line text input
- `text` - Multi-line text input
- `number` - Number input
- `boolean` - Switch
- `select` - Static options defined by `options`
- `plan` - Subscription plan selector; stores the selected plan ID, or `0` for no plan
- `json` - JSON CodeMirror editor with syntax highlighting and validation before saving
- `yaml` - YAML CodeMirror editor with syntax highlighting

Plan selector example:

```json
{
    "plan_id": {
        "type": "plan",
        "default": 0,
        "label": "Gift Plan",
        "placeholder": "Select a plan",
        "description": "The plan granted after the operation succeeds"
    }
}
```

## 🎯 Best Practices

### 1. Concise Main Class

- Plugin main class should be as concise as possible
- Mainly used for registering hooks and routes
- Complex logic should be placed in controllers or services

### 2. Configuration Management

- Define all configuration items in `config.json`
- Use `$this->getConfig()` to access configuration
- Provide default values for all configurations

### 3. Route Design

- Use semantic route prefixes
- Place API routes in `routes/api.php`
- Place Web routes in `routes/web.php`

### 4. Error Handling

```php
public function handle(Request $request)
{
    // Check plugin status
    if ($error = $this->beforePluginAction()) {
        return $error[1];
    }

    try {
        // Business logic
        return $this->success($result);
    } catch (\Exception $e) {
        return $this->fail([500, $e->getMessage()]);
    }
}
```

## 🔍 Debugging Tips

### 1. Logging

```php
\Log::info('Plugin operation', ['data' => $data]);
\Log::error('Plugin error', ['error' => $e->getMessage()]);
```

### 2. Configuration Checking

```php
// Check required configuration
if (!$this->getConfig('required_key')) {
    return $this->fail([400, 'Missing configuration']);
}
```

### 3. Development Mode

```php
if (config('app.debug')) {
    // Detailed debug information for development environment
}
```

## 📋 Plugin Lifecycle

1. **Installation**: Validate configuration, register to database
2. **Enable**: Load plugin, register hooks and routes
3. **Running**: Handle requests, execute business logic

## 🎉 Summary

Based on TelegramLogin plugin practical experience:

- **Simplicity**: Main class only 23 lines, focused on core functionality
- **Practicality**: Extends PluginController, convenient configuration access
- **Maintainability**: Clear directory structure, standard development patterns
- **Extensibility**: Hook-based architecture, easy to extend functionality

Following this guide, you can quickly develop plugins with complete functionality and concise code! 🚀

## 🖥️ Complete Plugin Artisan Commands Guide

### Feature Highlights

✅ **Auto Registration**: Automatically register all commands in `Commands/` directory when plugin is enabled  
✅ **Namespace Isolation**: Each plugin's commands use independent namespaces  
✅ **Type Safety**: Support PHP 8.2 type declarations  
✅ **Error Handling**: Comprehensive exception handling and error messages  
✅ **Configuration Integration**: Commands can access plugin configuration  
✅ **Interaction Support**: Support user input and confirmation operations

### Real Case Demonstrations

#### 1. Telegram Plugin Commands

```bash
# Test Bot connection
php artisan telegram:test ping

# Send message
php artisan telegram:test send --message="Hello World"

# Get Bot information
php artisan telegram:test info
```

#### 2. TelegramExtra Plugin Commands

```bash
# Show all statistics
php artisan telegram-extra:stats all

# User statistics
php artisan telegram-extra:stats users

# JSON format output
php artisan telegram-extra:stats users --format=json
```

#### 3. Example Plugin Commands

```bash
# Basic usage
php artisan example:hello

# With arguments and options
php artisan example:hello Bear --message="Welcome!"
```

### Development Best Practices

#### 1. Command Naming Conventions

```php
// ✅ Recommended: Use plugin name as prefix
protected $signature = 'telegram:test {action}';
protected $signature = 'telegram-extra:stats {type}';
protected $signature = 'example:hello {name}';

// ❌ Avoid: Use generic names
protected $signature = 'test {action}';
protected $signature = 'stats {type}';
```

#### 2. Error Handling Pattern

```php
public function handle(): int
{
    try {
        // Main logic
        return $this->executeAction();
    } catch (\Exception $e) {
        $this->error('Operation failed: ' . $e->getMessage());
        return 1;
    }
}
```

#### 3. User Interaction

```php
// Get user input
$chatId = $this->ask('Please enter chat ID');

// Confirm operation
if (!$this->confirm('Are you sure you want to execute this operation?')) {
    $this->info('Operation cancelled');
    return 0;
}

// Choose operation
$action = $this->choice('Choose operation', ['ping', 'send', 'info']);
```

#### 4. Configuration Access

```php
// Access plugin configuration in commands
protected function getConfig(string $key, $default = null): mixed
{
    // Get plugin instance through PluginManager
    $plugin = app(\App\Services\Plugin\PluginManager::class)
        ->getEnabledPlugins()['example_plugin'] ?? null;

    return $plugin ? $plugin->getConfig($key, $default) : $default;
}
```

### Advanced Usage

#### 1. Multi-Command Plugins

```php
// One plugin can have multiple commands
plugins/YourPlugin/Commands/
├── BackupCommand.php      # Backup command
├── CleanupCommand.php     # Cleanup command
├── StatsCommand.php       # Statistics command
└── TestCommand.php        # Test command
```

#### 2. Inter-Command Communication

```php
// Share data between commands through cache or database
Cache::put('plugin:backup:progress', $progress, 3600);
$progress = Cache::get('plugin:backup:progress');
```

#### 3. Scheduled Task Integration

```php
// Call commands in plugin's schedule method
public function schedule(Schedule $schedule): void
{
    $schedule->command('your-plugin:backup')->daily();
    $schedule->command('your-plugin:cleanup')->weekly();
}
```

### Debugging Tips

#### 1. Command Testing

```bash
# View command help
php artisan your-plugin:command --help

# Verbose output
php artisan your-plugin:command --verbose

# Debug mode
php artisan your-plugin:command --debug
```

#### 2. Logging

```php
// Log in commands
Log::info('Plugin command executed', [
    'command' => $this->signature,
    'arguments' => $this->arguments(),
    'options' => $this->options()
]);
```

#### 3. Performance Monitoring

```php
// Record command execution time
$startTime = microtime(true);
// ... execution logic
$endTime = microtime(true);
$this->info("Execution time: " . round(($endTime - $startTime) * 1000, 2) . "ms");
```

### Common Issues

#### Q: Commands not showing in list?

A: Check if plugin is enabled and ensure `Commands/` directory exists and contains valid command classes.

#### Q: Command execution failed?

A: Check if command class namespace is correct and ensure it extends `Illuminate\Console\Command`.

#### Q: How to access plugin configuration?

A: Get plugin instance through `PluginManager`, then call `getConfig()` method.

#### Q: Can commands call other commands?

A: Yes, use `Artisan::call()` method to call other commands.

```php
Artisan::call('other-plugin:command', ['arg' => 'value']);
```

### Summary

The plugin command system provides powerful extension capabilities for XBoard:

- 🚀 **Development Efficiency**: Quickly create management commands
- 🔧 **Operational Convenience**: Automate daily operations
- 📊 **Monitoring Capability**: Real-time system status viewing
- 🛠️ **Debug Support**: Convenient problem troubleshooting tools

By properly using plugin commands, you can greatly improve system maintainability and user experience! 🎉
