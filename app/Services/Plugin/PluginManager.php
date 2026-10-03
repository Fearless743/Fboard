<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use App\Support\SafeZip;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginManager
{
    protected string $pluginPath;
    protected string $corePluginPath;
    protected array $loadedPlugins = [];
    protected bool $pluginsInitialized = false;
    protected array $configTypesCache = [];

    public function __construct()
    {
        $this->pluginPath = base_path('plugins');
        $this->corePluginPath = base_path('plugins-core');
    }

    /**
     * 获取插件的命名空间
     */
    public function getPluginNamespace(string $pluginCode): string
    {
        return 'Plugin\\' . Str::studly($pluginCode);
    }

    public function resolvePluginPath(string $pluginCode): ?string
    {
        $dirName = Str::studly($pluginCode);
        $corePath = $this->corePluginPath . '/' . $dirName;
        if (File::isDirectory($corePath)) {
            return $corePath;
        }
        $userPath = $this->pluginPath . '/' . $dirName;
        if (File::isDirectory($userPath)) {
            return $userPath;
        }
        return null;
    }

    public function getPluginPath(string $pluginCode): string
    {
        return $this->resolvePluginPath($pluginCode)
            ?? $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function getUserPluginPath(string $pluginCode): string
    {
        return $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function isCorePlugin(string $pluginCode): bool
    {
        $dirName = Str::studly($pluginCode);
        return File::isDirectory($this->corePluginPath . '/' . $dirName);
    }

    public function getPluginPaths(): array
    {
        return [$this->corePluginPath, $this->pluginPath];
    }

    /**
     * 加载插件类
     */
    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
    {
        if (isset($this->loadedPlugins[$pluginCode])) {
            return $this->loadedPlugins[$pluginCode];
        }

        $pluginClass = $this->getPluginNamespace($pluginCode) . '\\Plugin';

        if (!class_exists($pluginClass)) {
            $pluginFile = $this->getPluginPath($pluginCode) . '/Plugin.php';
            if (!File::exists($pluginFile)) {
                Log::warning("Plugin class file not found: {$pluginFile}");
                Plugin::query()->where('code', $pluginCode)->delete();
                return null;
            }
            require_once $pluginFile;
        }

        if (!class_exists($pluginClass)) {
            Log::error("Plugin class not found: {$pluginClass}");
            return null;
        }

        $plugin = new $pluginClass($pluginCode);
        $this->loadedPlugins[$pluginCode] = $plugin;

        return $plugin;
    }

    /**
     * 注册插件的服务提供者
     */
    protected function registerServiceProvider(string $pluginCode): void
    {
        $providerClass = $this->getPluginNamespace($pluginCode) . '\\Providers\\PluginServiceProvider';

        if (class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    /**
     * 加载插件的路由
     */
    protected function loadRoutes(string $pluginCode): void
    {
        $routesPath = $this->getPluginPath($pluginCode) . '/routes';
        if (File::exists($routesPath)) {
            $webRouteFile = $routesPath . '/web.php';
            $apiRouteFile = $routesPath . '/api.php';
            if (File::exists($webRouteFile)) {
                Route::middleware('web')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($webRouteFile) {
                        require $webRouteFile;
                    });
            }
            if (File::exists($apiRouteFile)) {
                Route::middleware('api')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($apiRouteFile) {
                        require $apiRouteFile;
                    });
            }
        }
    }

    /**
     * 加载插件的视图
     */
    protected function loadViews(string $pluginCode): void
    {
        $viewsPath = $this->getPluginPath($pluginCode) . '/resources/views';
        if (File::exists($viewsPath)) {
            View::addNamespace(Str::studly($pluginCode), $viewsPath);
            return;
        }
    }

    /**
     * 注册插件命令
     */
    protected function registerPluginCommands(string $pluginCode, AbstractPlugin $pluginInstance): void
    {
        try {
            // 调用插件的命令注册方法
            $pluginInstance->registerCommands();
        } catch (\Exception $e) {
            Log::error("Failed to register commands for plugin '{$pluginCode}': " . $e->getMessage());
        }
    }

    /**
     * 安装插件
     */
    public function install(string $pluginCode): bool
    {
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';

        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config');
        }

        // 检查插件是否已安装
        if (Plugin::where('code', $pluginCode)->exists()) {
            throw new \Exception('Plugin already installed');
        }

        // 检查依赖
        if (!$this->checkDependencies($config['require'] ?? [])) {
            throw new \Exception('Dependencies not satisfied');
        }

        // 运行数据库迁移
        $this->runMigrations(pluginCode: $pluginCode);

        DB::beginTransaction();
        try {
            // 提取配置默认值
            $defaultValues = $this->extractDefaultConfig($config);

            // 创建插件实例
            $plugin = $this->loadPlugin($pluginCode);

            // 注册到数据库
            Plugin::create([
                'code' => $pluginCode,
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'] ?? Plugin::TYPE_FEATURE,
                'is_enabled' => false,
                'config' => json_encode($defaultValues),
                'installed_at' => now(),
            ]);

            // 运行插件安装方法
            if (method_exists($plugin, 'install')) {
                $plugin->install();
            }

            // 发布插件资源
            $this->publishAssets($pluginCode);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 提取插件默认配置
     */
    protected function extractDefaultConfig(array $config): array
    {
        $defaultValues = [];
        if (isset($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $item) {
                if (is_array($item)) {
                    $defaultValues[$key] = $item['default'] ?? null;
                } else {
                    $defaultValues[$key] = $item;
                }
            }
        }
        return $defaultValues;
    }

    /**
     * 获取 Migrator 实例并确保迁移仓库存在
     */
    protected function getMigrator(): \Illuminate\Database\Migrations\Migrator
    {
        $migrator = app('migrator');

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    /**
     * 运行插件数据库迁移
     */
    protected function runMigrations(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->run([$migrationsPath]);
        }
    }

    /**
     * 回滚插件数据库迁移
     */
    protected function runMigrationsRollback(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->rollback([$migrationsPath]);
        }
    }

    /**
     * 获取插件的静态文件目录（public 目录）
     */
    public function getPluginPublicPath(string $pluginCode): string
    {
        return $this->getPluginPath($pluginCode) . '/public';
    }

    /**
     * 获取插件中可用的 HTML 静态文件列表
     * 安全限制：只返回 .html / .htm 文件，防路径遍历
     */
    public function getStaticFiles(string $pluginCode): array
    {
        // 插件代码仅允许小写字母、数字、下划线
        if (!preg_match('/^[a-z0-9_]+$/', $pluginCode)) {
            return [];
        }

        $sourcePath = $this->getPluginPath($pluginCode) . '/public';
        if (!File::exists($sourcePath)) {
            return [];
        }

        $allowedExtensions = ['html', 'htm'];
        $files = [];
        $allFiles = File::allFiles($sourcePath);
        foreach ($allFiles as $file) {
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (str_contains($relativePath, '..')) {
                continue;
            }

            $files[] = [
                'name' => $file->getFilename(),
                'path' => $relativePath,
                'extension' => $extension,
                'size' => $file->getSize(),
                'last_modified' => $file->getMTime(),
                'url' => '/plugins/' . $pluginCode . '/' . $relativePath,
            ];
        }

        return $files;
    }

    /**
     * 发布插件 resources/assets 资源
     * 注意：public/ 目录的静态文件由路由直接代理提供，不复制
     */
    protected function publishAssets(string $pluginCode): void
    {
        $publishPath = public_path('plugins/' . $pluginCode);

        $assetsPath = $this->getPluginPath($pluginCode) . '/resources/assets';
        if (File::exists($assetsPath)) {
            File::ensureDirectoryExists($publishPath);
            File::copyDirectory($assetsPath, $publishPath);
        }
    }

    /**
     * 验证配置文件
     */
    protected function validateConfig(array $config): bool
    {
        $requiredFields = [
            'name',
            'code',
            'version',
            'description',
            'author'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        // 验证插件代码格式
        if (!preg_match('/^[a-z0-9_]+$/', $config['code'])) {
            return false;
        }

        // 验证版本号格式
        if (!preg_match('/^\d+\.\d+\.\d+$/', $config['version'])) {
            return false;
        }

        // 验证插件类型
        if (isset($config['type'])) {
            $validTypes = ['feature', 'payment', 'protocol'];
            if (!in_array($config['type'], $validTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 启用插件
     */
    public function enable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);

        if (!$plugin) {
            Plugin::where('code', $pluginCode)->delete();
            throw new \Exception('Plugin not found: ' . $pluginCode);
        }

        // 获取插件配置
        $dbPlugin = Plugin::query()
            ->where('code', $pluginCode)
            ->first();

        if ($dbPlugin && !empty($dbPlugin->config)) {
            $values = json_decode($dbPlugin->config, true) ?: [];
            $values = $this->castConfigValuesByType($pluginCode, $values);
            $plugin->setConfig($values);
        }

        // 注册服务提供者
        $this->registerServiceProvider($pluginCode);

        // 加载路由
        $this->loadRoutes($pluginCode);

        // 加载视图
        $this->loadViews($pluginCode);

        // 更新数据库状态
        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => true,
                'updated_at' => now(),
            ]);
        // 初始化插件
        $plugin->boot();

        return true;
    }

    /**
     * 禁用插件
     */
    public function disable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \Exception('Plugin not found');
        }

        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);

        $plugin->cleanup();

        return true;
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $pluginCode): bool
    {
        $this->disable($pluginCode);
        $this->runMigrationsRollback($pluginCode);
        Plugin::query()->where('code', $pluginCode)->delete();

        return true;
    }

    /**
     * 删除插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function delete(string $pluginCode): bool
    {
        if (Plugin::where('code', $pluginCode)->exists()) {
            $this->uninstall($pluginCode);
        }

        if ($this->isCorePlugin($pluginCode)) {
            throw new \Exception('核心插件不允许删除');
        }

        $pluginPath = $this->getUserPluginPath($pluginCode);
        if (!File::exists($pluginPath)) {
            throw new \Exception('插件不存在');
        }

        File::deleteDirectory($pluginPath);

        return true;
    }

    /**
     * 检查依赖关系
     */
    protected function checkDependencies(array $requires): bool
    {
        foreach ($requires as $package => $version) {
            if ($package === 'fboard' || $package === 'xboard') {
                // 检查 fboard 版本（xboard 为旧插件元数据兼容键）
                // 实现版本比较逻辑
            }
        }
        return true;
    }

    /**
     * 升级插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function update(string $pluginCode): bool
    {
        $dbPlugin = Plugin::where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \Exception('Plugin not installed: ' . $pluginCode);
        }

        // 获取插件配置文件中的最新版本
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$config || !isset($config['version'])) {
            throw new \Exception('Invalid plugin config or missing version');
        }

        $newVersion = $config['version'];
        $oldVersion = $dbPlugin->version;

        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new \Exception('Plugin is already up to date');
        }

        $this->disable($pluginCode);
        $this->runMigrations($pluginCode);

        $plugin = $this->loadPlugin($pluginCode);
            if ($plugin) {
                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $plugin->setConfig($values);
                }

                $plugin->update($oldVersion, $newVersion);
            }

        // 升级时重新发布静态文件
        $this->publishAssets($pluginCode);

        $dbPlugin->update([
            'version' => $newVersion,
            'updated_at' => now(),
        ]);

        $this->enable($pluginCode);

        return true;
    }

    /**
     * 上传插件
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return bool
     * @throws \Exception
     */
    public function upload($file): bool
    {
        $tmpPath = storage_path('tmp/plugins');
        if (!File::exists($tmpPath)) {
            File::makeDirectory($tmpPath, 0755, true);
        }

        $extractPath = $tmpPath . '/' . uniqid();
        $zip = new \ZipArchive();

        if ($zip->open($file->path()) !== true) {
            throw new \Exception('无法打开插件包文件');
        }

        SafeZip::extractTo($zip, $extractPath);
        $zip->close();

        $configFile = File::glob($extractPath . '/*/config.json');
        if (empty($configFile)) {
            $configFile = File::glob($extractPath . '/config.json');
        }

        if (empty($configFile)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件包格式错误：缺少配置文件');
        }

        $pluginPath = dirname(reset($configFile));
        $config = json_decode(File::get($pluginPath . '/config.json'), true);

        if (!$this->validateConfig($config)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件配置文件格式错误');
        }

        $targetPath = $this->getUserPluginPath($config['code']);
        if (File::exists($targetPath)) {
            $installedConfigPath = $targetPath . '/config.json';
            if (!File::exists($installedConfigPath)) {
                throw new \Exception('已安装插件缺少配置文件，无法判断是否可升级');
            }
            $installedConfig = json_decode(File::get($installedConfigPath), true);

            $oldVersion = $installedConfig['version'] ?? null;
            $newVersion = $config['version'] ?? null;
            if (!$oldVersion || !$newVersion) {
                throw new \Exception('插件缺少版本号，无法判断是否可升级');
            }
            if (version_compare($newVersion, $oldVersion, '<=')) {
                throw new \Exception('上传插件版本不高于已安装版本，无法升级');
            }

            File::deleteDirectory($targetPath);
        }

        File::copyDirectory($pluginPath, $targetPath);
        File::deleteDirectory($pluginPath);
        File::deleteDirectory($extractPath);

        if (Plugin::where('code', $config['code'])->exists()) {
            return $this->update($config['code']);
        }

        return true;
    }

    /**
     * Initializes all enabled plugins from the database.
     * This method ensures that plugins are loaded, and their routes, views,
     * and service providers are registered only once per request cycle.
     */
    public function initializeEnabledPlugins(): void
    {
        if ($this->pluginsInitialized) {
            return;
        }

        $enabledPlugins = Plugin::where('is_enabled', true)->get();

        foreach ($enabledPlugins as $dbPlugin) {
            try {
                $pluginCode = $dbPlugin->code;

                $pluginInstance = $this->loadPlugin($pluginCode);
                if (!$pluginInstance) {
                    continue;
                }

                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $pluginInstance->setConfig($values);
                }

                $this->registerServiceProvider($pluginCode);
                $this->loadRoutes($pluginCode);
                $this->loadViews($pluginCode);
                $this->registerPluginCommands($pluginCode, $pluginInstance);

                $pluginInstance->boot();

            } catch (\Exception $e) {
                Log::error("Failed to initialize plugin '{$dbPlugin->code}': " . $e->getMessage());
            }
        }

        $this->pluginsInitialized = true;
    }

    /**
     * Register scheduled tasks for all enabled plugins.
     * Called from Console Kernel. Only loads main plugin class and config for scheduling.
     * Avoids full HTTP/plugin boot overhead.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function registerPluginSchedules(Schedule $schedule): void
    {
        Plugin::where('is_enabled', true)
            ->get()
            ->each(function ($dbPlugin) use ($schedule) {
                try {
                    $pluginInstance = $this->loadPlugin($dbPlugin->code);
                    if (!$pluginInstance) {
                        return;
                    }
                    if (!empty($dbPlugin->config)) {
                        $values = json_decode($dbPlugin->config, true) ?: [];
                        $values = $this->castConfigValuesByType($dbPlugin->code, $values);
                        $pluginInstance->setConfig($values);
                    }
                    $pluginInstance->schedule($schedule);

                } catch (\Exception $e) {
                    Log::error("Failed to register schedule for plugin '{$dbPlugin->code}': " . $e->getMessage());
                }
            });
    }

    /**
     * Get all enabled plugin instances.
     *
     * This method ensures that all enabled plugins are initialized and then returns them.
     * It's the central point for accessing active plugins.
     *
     * @return array<AbstractPlugin>
     */
    public function getEnabledPlugins(): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled plugins by type
     */
    public function getEnabledPluginsByType(string $type): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->byType($type)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled payment plugins
     */
    public function getEnabledPaymentPlugins(): array
    {
        return $this->getEnabledPluginsByType('payment');
    }

    /**
     * 收集所有已启用插件注册的管理后台 UI 扩展块。
     *
     * 数据来源：
     *   1. 各插件 config.json 的 admin_ui 静态声明；
     *   2. 插件在 boot() 中通过 registerAdminExtension() 动态注册（admin.ui.extensions filter）。
     * 返回前统一归一化、按 id 去重并按 priority 升序排序。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminUiExtensions(): array
    {
        $this->initializeEnabledPlugins();

        $declarations = [];

        $enabledCodes = Plugin::query()
            ->where('is_enabled', true)
            ->pluck('code')
            ->all();

        foreach ($enabledCodes as $code) {
            $configFile = $this->getPluginPath((string) $code) . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }

            $config = json_decode(File::get($configFile), true);
            if (!is_array($config)) {
                continue;
            }

            $adminUi = $config['admin_ui'] ?? [];
            if (!is_array($adminUi)) {
                continue;
            }

            foreach ($adminUi as $declaration) {
                if (!is_array($declaration)) {
                    continue;
                }
                $declaration['plugin'] = (string) $code;
                $declarations[] = $declaration;
            }
        }

        $declarations = HookManager::filter('admin.ui.extensions', $declarations);

        $extensions = [];
        $seen = [];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }

            $pluginCode = (string) ($declaration['plugin'] ?? '');
            $normalized = self::normalizeAdminUiExtension($pluginCode, $declaration);
            if ($normalized === null) {
                continue;
            }

            $id = (string) $normalized['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $extensions[] = $normalized;
        }

        usort($extensions, static function (array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']);
        });

        return $extensions;
    }

    /**
     * 收集所有已启用插件注册的后台导航（菜单 + 整页 + 翻译）。
     *
     * 数据来源：
     *   1. 各插件 config.json 的 admin_nav.menus / admin_nav.pages / admin_nav.i18n 静态声明；
     *   2. 插件在 boot() 中通过 registerAdminMenu()/registerAdminPage()/registerAdminI18n() 动态注册
     *      （admin.ui.menus / admin.ui.pages / admin.ui.i18n filter）。
     *
     * @return array{menus: array<int, array<string, mixed>>, pages: array<int, array<string, mixed>>, i18n: array<string, array<string, mixed>>}
     */
    public function getAdminUiNavigation(): array
    {
        $this->initializeEnabledPlugins();

        $menus = [];
        $pages = [];
        $i18n = [];

        $enabledCodes = Plugin::query()
            ->where('is_enabled', true)
            ->pluck('code')
            ->all();

        foreach ($enabledCodes as $code) {
            $configFile = $this->getPluginPath((string) $code) . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }

            $config = json_decode(File::get($configFile), true);
            if (!is_array($config)) {
                continue;
            }

            $nav = $config['admin_nav'] ?? [];
            if (!is_array($nav)) {
                continue;
            }

            foreach ((array) ($nav['menus'] ?? []) as $menu) {
                if (is_array($menu)) {
                    $menus[] = array_merge(['plugin' => (string) $code], $menu);
                }
            }
            foreach ((array) ($nav['pages'] ?? []) as $page) {
                if (is_array($page)) {
                    $pages[] = array_merge(['plugin' => (string) $code], $page);
                }
            }
            foreach ((array) ($nav['i18n'] ?? []) as $lang => $bundle) {
                if (is_string($lang) && is_array($bundle)) {
                    $i18n[$lang] = array_replace_recursive($i18n[$lang] ?? [], $bundle);
                }
            }
        }

        $menus = HookManager::filter('admin.ui.menus', $menus);
        $pages = HookManager::filter('admin.ui.pages', $pages);
        $i18n = HookManager::filter('admin.ui.i18n', $i18n);

        $normalizedMenus = [];
        $seenMenuKeys = [];
        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }
            $normalized = self::normalizeAdminUiMenu((string) ($menu['plugin'] ?? ''), $menu);
            if ($normalized === null) {
                continue;
            }
            // 同一 path 去重，先到先得
            if (isset($seenMenuKeys[$normalized['path']])) {
                continue;
            }
            $seenMenuKeys[$normalized['path']] = true;
            $normalizedMenus[] = $normalized;
        }
        usort($normalizedMenus, static function (array $a, array $b): int {
            return ($a['order'] <=> $b['order']);
        });

        $normalizedPages = [];
        $seenPageKeys = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $normalized = self::normalizeAdminUiPage((string) ($page['plugin'] ?? ''), $page);
            if ($normalized === null) {
                continue;
            }
            if (isset($seenPageKeys[$normalized['path']])) {
                continue;
            }
            $seenPageKeys[$normalized['path']] = true;
            $normalizedPages[] = $normalized;
        }

        return [
            'menus' => $normalizedMenus,
            'pages' => $normalizedPages,
            'i18n' => $i18n,
        ];
    }

    /**
     * 归一化后台菜单项，非法返回 null。
     *
     * @param string $pluginCode
     * @param array<string, mixed> $menu
     * @return array<string, mixed>|null
     */
    public static function normalizeAdminUiMenu(string $pluginCode, array $menu): ?array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $pluginCode)) {
            return null;
        }

        $external = (bool) ($menu['external'] ?? false);
        $path = trim((string) ($menu['path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $isUrl = preg_match('#^(https?:)?//#i', $path) === 1;
        if (!$external && !$isUrl) {
            // 后台路由：去掉前导斜杠
            $path = ltrim($path, '/');
            if ($path === '' || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
                return null;
            }
        }
        if (($external || $isUrl) && !$isUrl && str_starts_with($path, '/')) {
            // 站内绝对路径视为 external 直接跳转
        }

        $label = trim((string) ($menu['label'] ?? ''));
        $i18nKey = trim((string) ($menu['i18nKey'] ?? ''));
        if ($label === '' && $i18nKey === '') {
            return null;
        }

        $target = (string) ($menu['target'] ?? '_blank');
        if (!in_array($target, ['_blank', '_self'], true)) {
            $target = '_blank';
        }

        $group = trim((string) ($menu['group'] ?? ''));
        $groupLabel = trim((string) ($menu['groupLabel'] ?? ''));

        return [
            'plugin' => $pluginCode,
            'path' => $path,
            'label' => $label,
            'i18nKey' => $i18nKey !== '' ? $i18nKey : null,
            'icon' => trim((string) ($menu['icon'] ?? '')) ?: null,
            'group' => $group !== '' ? $group : null,
            'groupLabel' => $groupLabel !== '' ? $groupLabel : null,
            'order' => (int) ($menu['order'] ?? 100),
            'external' => $external || $isUrl,
            'target' => $target,
        ];
    }

    /**
     * 归一化后台整页定义，非法返回 null。
     *
     * @param string $pluginCode
     * @param array<string, mixed> $page
     * @return array<string, mixed>|null
     */
    public static function normalizeAdminUiPage(string $pluginCode, array $page): ?array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $pluginCode)) {
            return null;
        }

        $path = ltrim(trim((string) ($page['path'] ?? '')), '/');
        if ($path === '' || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
            return null;
        }

        $type = (string) ($page['type'] ?? 'iframe');
        if (!in_array($type, ['component', 'html', 'iframe'], true)) {
            $type = 'iframe';
        }

        $item = [
            'plugin' => $pluginCode,
            'path' => $path,
            'title' => isset($page['title']) ? (string) $page['title'] : null,
            'type' => $type,
            'component' => trim((string) ($page['component'] ?? '')),
            'html' => null,
            'url' => null,
            'script' => self::resolveAdminUiAssetUrl($pluginCode, $page['script'] ?? null),
            'style' => self::resolveAdminUiAssetUrl($pluginCode, $page['style'] ?? null),
            'height' => isset($page['height']) ? (string) $page['height'] : '100%',
        ];

        if ($type === 'html') {
            if (!isset($page['html']) || !is_string($page['html']) || $page['html'] === '') {
                return null;
            }
            $item['html'] = $page['html'];
        } elseif ($type === 'iframe') {
            $url = self::resolveAdminUiAssetUrl($pluginCode, $page['url'] ?? null);
            if ($url === null) {
                return null;
            }
            $item['url'] = $url;
        } elseif ($item['component'] === '') {
            return null;
        }

        return $item;
    }

    /**
     * 归一化单个 UI 扩展块，非法条目返回 null。
     *
     * @param string $pluginCode
     * @param array<string, mixed> $extension
     * @return array<string, mixed>|null
     */
    public static function normalizeAdminUiExtension(string $pluginCode, array $extension): ?array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $pluginCode)) {
            return null;
        }

        $rawId = trim((string) ($extension['id'] ?? ''));
        if ($rawId === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $rawId)) {
            return null;
        }

        $type = (string) ($extension['type'] ?? 'component');
        if (!in_array($type, ['component', 'html', 'iframe', 'button', 'link'], true)) {
            $type = 'component';
        }

        $item = [
            'id' => $pluginCode . ':' . $rawId,
            'name' => $rawId,
            'plugin' => $pluginCode,
            'slot' => null,
            'page' => self::normalizeAdminUiPages($extension['page'] ?? null),
            'priority' => (int) ($extension['priority'] ?? 20),
            'title' => isset($extension['title']) ? (string) $extension['title'] : null,
            'type' => $type,
            'component' => trim((string) ($extension['component'] ?? $rawId)),
            'context' => is_array($extension['context'] ?? null) ? $extension['context'] : [],
            'script' => self::resolveAdminUiAssetUrl($pluginCode, $extension['script'] ?? null),
            'style' => self::resolveAdminUiAssetUrl($pluginCode, $extension['style'] ?? null),
            'html' => null,
            'url' => null,
            'label' => isset($extension['label']) ? (string) $extension['label'] : null,
            'action' => isset($extension['action']) ? (string) $extension['action'] : null,
            'params' => is_array($extension['params'] ?? null) ? $extension['params'] : [],
            'confirm' => isset($extension['confirm']) ? (string) $extension['confirm'] : null,
            'variant' => in_array(($extension['variant'] ?? 'default'), ['default', 'outline', 'destructive', 'ghost', 'link'], true)
                ? (string) ($extension['variant'] ?? 'default')
                : 'default',
            'icon' => isset($extension['icon']) ? (string) $extension['icon'] : null,
            'anchor' => null,
        ];

        $slot = $extension['slot'] ?? null;
        if (is_string($slot) && trim($slot) !== '') {
            $item['slot'] = trim($slot);
        }

        // anchor 定位：字符串选择器或 ['selector'=>..., 'position'=>...]
        $anchor = $extension['anchor'] ?? null;
        if (is_string($anchor) && trim($anchor) !== '') {
            $anchor = ['selector' => trim($anchor)];
        }
        if (is_array($anchor) && isset($anchor['selector']) && trim((string) $anchor['selector']) !== '') {
            $position = (string) ($anchor['position'] ?? 'append');
            if (!in_array($position, ['before', 'after', 'prepend', 'append'], true)) {
                $position = 'append';
            }
            $item['anchor'] = [
                'selector' => trim((string) $anchor['selector']),
                'position' => $position,
            ];
            if (isset($anchor['page'])) {
                $item['page'] = self::normalizeAdminUiPages($anchor['page']);
            }
        }

        // 必须能定位到具名插槽或 CSS 锚点之一
        if ($item['slot'] === null && $item['anchor'] === null) {
            return null;
        }

        if ($type === 'html') {
            if (!isset($extension['html']) || !is_string($extension['html']) || $extension['html'] === '') {
                return null;
            }
            $item['html'] = $extension['html'];
        } elseif ($type === 'iframe') {
            $url = self::resolveAdminUiAssetUrl($pluginCode, $extension['url'] ?? null);
            if ($url === null) {
                return null;
            }
            $item['url'] = $url;
        } elseif ($type === 'button' || $type === 'link') {
            if ($item['label'] === null || $item['label'] === '') {
                return null;
            }
            $url = self::resolveAdminUiAssetUrl($pluginCode, $extension['url'] ?? null);
            $item['url'] = $url;
            if ($type === 'link' && $url === null && $item['action'] === null) {
                return null;
            }
        } elseif ($item['component'] === '') {
            return null;
        }

        return $item;
    }

    /**
     * 解析扩展块资源地址：绝对 URL / 站内绝对路径原样返回，相对路径拼到 /plugins/{code}/。
     */
    protected static function resolveAdminUiAssetUrl(string $pluginCode, mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^(https?:)?//#i', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return '/plugins/' . $pluginCode . '/' . ltrim($path, '/');
    }

    /**
     * 归一化扩展块生效页面列表，支持字符串、数组，默认 ['*']。
     *
     * @param mixed $page
     * @return list<string>
     */
    protected static function normalizeAdminUiPages(mixed $page): array
    {
        if (is_string($page)) {
            $page = [$page];
        }
        if (!is_array($page)) {
            return ['*'];
        }

        $pages = [];
        foreach ($page as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = ltrim(trim($value), '/');
            if ($value === '') {
                continue;
            }
            $pages[] = $value;
        }

        if ($pages === []) {
            return ['*'];
        }

        return array_values(array_unique($pages));
    }

    /**
     * install default protocol plugins from plugins-core/
     */
    public static function installDefaultProtocols(): void
    {
        $pluginManager = app(self::class);
        $coreDir = base_path('plugins-core');

        if (!File::isDirectory($coreDir)) {
            return;
        }

        foreach (File::directories($coreDir) as $directory) {
            $configFile = $directory . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }
            $config = json_decode(File::get($configFile), true);
            $code = $config['code'] ?? null;
            $type = $config['type'] ?? null;
            if (!$code || $type !== 'protocol') {
                continue;
            }
            if (!Plugin::where('code', $code)->exists()) {
                try {
                    $pluginManager->install($code);
                    $pluginManager->enable($code);
                    Log::info("Installed and enabled protocol plugin: {$code}");
                } catch (\Exception $e) {
                    Log::warning("Could not install protocol plugin {$code}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * install default plugins
     */
    public static function installDefaultPlugins(): void
    {
        $pluginManager = app(self::class);
        $coreDir = base_path('plugins-core');

        if (!File::isDirectory($coreDir)) {
            return;
        }

        foreach (File::directories($coreDir) as $directory) {
            $configFile = $directory . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }
            $config = json_decode(File::get($configFile), true);
            $code = $config['code'] ?? null;
            if (!$code) {
                continue;
            }
            if (!Plugin::where('code', $code)->exists()) {
                $pluginManager->install($code);
                $pluginManager->enable($code);
                Log::info("Installed and enabled core plugin: {$code}");
            }
        }
    }

    /**
     * 根据 config.json 的类型信息对配置值进行类型转换（仅处理 type=json 键）。
     */
    protected function castConfigValuesByType(string $pluginCode, array $values): array
    {
        $types = $this->getConfigTypes($pluginCode);
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;

            if ($type === 'json') {
                if (is_array($value)) {
                    continue;
                }
                
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $values[$key] = $decoded;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * 读取并缓存插件 config.json 中的键类型映射。
     */
    protected function getConfigTypes(string $pluginCode): array
    {
        if (isset($this->configTypesCache[$pluginCode])) {
            return $this->configTypesCache[$pluginCode];
        }
        $types = [];
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $fields = $config['config'] ?? [];
            foreach ($fields as $key => $meta) {
                $types[$key] = is_array($meta) ? ($meta['type'] ?? 'string') : 'string';
            }
        }
        $this->configTypesCache[$pluginCode] = $types;
        return $types;
    }
}