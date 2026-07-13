<?php

namespace App\Services\Plugin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractPlugin
{
    protected array $config = [];
    protected string $basePath;
    protected string $pluginCode;
    protected string $namespace;

    public function __construct(string $pluginCode)
    {
        $this->pluginCode = $pluginCode;
        $this->namespace = 'Plugin\\' . Str::studly($pluginCode);
        $reflection = new \ReflectionClass($this);
        $this->basePath = dirname($reflection->getFileName());
    }

    /**
     * 获取插件代码
     */
    public function getPluginCode(): string
    {
        return $this->pluginCode;
    }

    /**
     * 获取插件命名空间
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * 获取插件基础路径
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * 设置配置
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * 获取配置
     */
    public function getConfig(?string $key = null, $default = null): mixed
    {
        $config = $this->config;
        if ($key) {
            $config = $config[$key] ?? $default;
        }
        return $config;
    }

    /**
     * 获取视图
     */
    protected function view(string $view, array $data = [], array $mergeData = []): \Illuminate\Contracts\View\View
    {
        return view(Str::studly($this->pluginCode) . '::' . $view, $data, $mergeData);
    }

    /**
     * 注册动作钩子监听器
     */
    protected function listen(string $hook, callable $callback, int $priority = 20): void
    {
        HookManager::register($hook, $callback, $priority);
    }

    /**
     * 注册过滤器钩子
     */
    protected function filter(string $hook, callable $callback, int $priority = 20): void
    {
        HookManager::registerFilter($hook, $callback, $priority);
    }

    /**
     * 注册协议定义
     * 插件可调用此方法在 boot() 中注册协议类型定义，
     * 前端将自动获取协议类型及配置字段用于动态渲染表单。
     */
    protected function registerProtocolDefinition(string $type, string $name, array $configFields, array $validationRules = []): void
    {
        $this->filter('protocols.definitions', function ($definitions) use ($type, $name, $configFields, $validationRules) {
            $definitions[$type] = [
                'name' => $name,
                'config_fields' => $configFields,
                'validation_rules' => $validationRules,
            ];
            return $definitions;
        });
    }

    /**
     * 移除事件监听器
     */
    protected function removeListener(string $hook): void
    {
        HookManager::remove($hook);
    }

    /**
     * 注册 Artisan 命令
     */
    protected function registerCommand(string $commandClass): void
    {
        if (class_exists($commandClass)) {
            app('Illuminate\Contracts\Console\Kernel')->registerCommand(new $commandClass());
        }
    }

    /**
     * 注册插件动作按钮
     * 插件可在 boot() 中调用此方法注册自定义操作按钮，点击后执行回调函数。
     * 按钮将显示在管理后台插件列表页的已启用插件卡片上。
     *
     * @param string $name 动作标识（蛇形命名，如 'sync_users'）
     * @param string $label 按钮显示文本（如 '同步用户'）
     * @param callable $handler 回调函数，接收 array $params 参数，返回 array
     * @param array $options 可选配置：
     *   - 'icon' => string, 图标 emoji（默认 '⚡'）
     *   - 'confirm' => string|null, 确认弹窗提示文本（null 则不确认直接执行）
     *   - 'color' => string, 按钮颜色样式（'default'|'destructive'|'outline'）
     * @return void
     */
    protected function registerAction(string $name, string $label, callable $handler, array $options = []): void
    {
        // 注册动作元数据（供前端展示）
        $this->filter('plugin.actions', function ($actions) use ($name, $label, $options) {
            $actions[$this->pluginCode][] = [
                'name' => $name,
                'label' => $label,
                'icon' => $options['icon'] ?? '⚡',
                'confirm' => $options['confirm'] ?? null,
                'color' => $options['color'] ?? 'default',
            ];
            return $actions;
        });

        // 注册动作执行句柄（filter 以便返回结果）
        $this->filter('plugin.action.execute.' . $this->pluginCode . '.' . $name, $handler);
    }

    /**
     * 注册插件命令目录
     */
    public function registerCommands(): void
    {
        $commandsPath = $this->basePath . '/Commands';
        if (File::exists($commandsPath)) {
            $files = File::glob($commandsPath . '/*.php');
            foreach ($files as $file) {
                $className = pathinfo($file, PATHINFO_FILENAME);
                $commandClass = $this->namespace . '\\Commands\\' . $className;
                
                if (class_exists($commandClass)) {
                    $this->registerCommand($commandClass);
                }
            }
        }
    }

    /**
     * 中断当前请求并返回新的响应
     *
     * @param Response|string|array $response
     * @return never
     */
    protected function intercept(Response|string|array $response): never
    {
        HookManager::intercept($response);
    }

    /**
     * 插件启动时调用
     */
    public function boot(): void
    {
        // 插件启动时的初始化逻辑
    }

    /**
     * 插件安装时调用
     */
    public function install(): void
    {
        // 插件安装时的初始化逻辑
    }

    /**
     * 插件卸载时调用
     */
    public function cleanup(): void
    {
        // 插件卸载时的清理逻辑
    }

    /**
     * 插件更新时调用
     */
    public function update(string $oldVersion, string $newVersion): void
    {
        // 插件更新时的迁移逻辑
    }

    /**
     * 获取插件资源URL
     */
    protected function asset(string $path): string
    {
        return asset('plugins/' . $this->pluginCode . '/' . ltrim($path, '/'));
    }

    /**
     * 获取插件配置项
     */
    protected function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * 获取插件数据库迁移路径
     */
    protected function getMigrationsPath(): string
    {
        return $this->basePath . '/database/migrations';
    }

    /**
     * 获取插件视图路径
     */
    protected function getViewsPath(): string
    {
        return $this->basePath . '/resources/views';
    }

    /**
     * 获取插件资源路径
     */
    protected function getAssetsPath(): string
    {
        return $this->basePath . '/resources/assets';
    }

    /**
     * Register plugin scheduled tasks. Plugins can override this method.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        // Plugin can override this method to register scheduled tasks
    }
}