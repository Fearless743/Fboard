<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PluginController extends Controller
{
    protected PluginManager $pluginManager;
    protected PluginConfigService $configService;

    public function __construct(
        PluginManager $pluginManager,
        PluginConfigService $configService
    ) {
        $this->pluginManager = $pluginManager;
        $this->configService = $configService;
    }

    /**
     * 获取所有插件类型
     */
    public function types()
    {
        return response()->json([
            'data' => [
                [
                    'value' => Plugin::TYPE_FEATURE,
                    'label' => '功能',
                    'description' => '提供功能扩展的插件，如Telegram登录、邮件通知等',
                    'icon' => '🔧'
                ],
                [
                    'value' => Plugin::TYPE_PAYMENT,
                    'label' => '支付方式',
                    'description' => '提供支付接口的插件，如支付宝、微信支付等',
                    'icon' => '💳'
                ],
                [
                    'value' => Plugin::TYPE_PROTOCOL,
                    'label' => '协议',
                    'description' => '提供协议支持的插件，如Clash、Sing-Box等',
                    'icon' => '🔌'
                ]
            ]
        ]);
    }

    /**
     * 获取插件列表（分页）
     *
     * 支持的可选参数：
     *   - type: 插件类型过滤（feature / payment / protocol）
     *   - search: 在 name / description 上做大小写不敏感的模糊匹配
     *   - page / pageSize: 分页
     */
    public function index(Request $request)
    {
        $type = $request->query('type');
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min(100, (int) $request->query('pageSize', 20)));
        $search = trim((string) $request->query('search', ''));
        $searchLower = mb_strtolower($search);

        $installedPlugins = Plugin::all()
            ->keyBy('code')
            ->toArray();

        $allPlugins = [];
        $seenCodes = [];

        foreach ($this->pluginManager->getPluginPaths() as $pluginPath) {
            if (!File::exists($pluginPath)) {
                continue;
            }
            $directories = File::directories($pluginPath);
            foreach ($directories as $directory) {
                $configFile = $directory . '/config.json';
                if (!File::exists($configFile)) {
                    continue;
                }
                $config = json_decode(File::get($configFile), true);
                if (!$config || !isset($config['code'])) {
                    continue;
                }
                $code = $config['code'];

                if (isset($seenCodes[$code])) {
                    continue;
                }
                $seenCodes[$code] = true;

                $pluginType = $config['type'] ?? Plugin::TYPE_FEATURE;
                if ($type && $pluginType !== $type) {
                    continue;
                }

                // 名称/描述模糊匹配（插件是文件系统扫描，in-memory 过滤即可）
                if ($search !== '' && $searchLower !== '') {
                    $nameMatch = mb_stripos((string) ($config['name'] ?? ''), $searchLower) !== false;
                    $descMatch = mb_stripos((string) ($config['description'] ?? ''), $searchLower) !== false;
                    if (!$nameMatch && !$descMatch) {
                        continue;
                    }
                }

                $installed = isset($installedPlugins[$code]);
                $pluginConfigDef = $config['config'] ?? [];
                $hasConfig = is_array($pluginConfigDef) ? count($pluginConfigDef) > 0 : false;
                $readmeFile = collect(['README.md', 'readme.md'])
                    ->map(fn($f) => $directory . '/' . $f)
                    ->first(fn($path) => File::exists($path));
                $needUpgrade = false;
                if ($installed) {
                    $installedVersion = $installedPlugins[$code]['version'] ?? null;
                    $localVersion = $config['version'] ?? null;
                    if ($installedVersion && $localVersion && version_compare($localVersion, $installedVersion, '>')) {
                        $needUpgrade = true;
                    }
                }
                $isCore = $this->pluginManager->isCorePlugin($code);
                // 检查是否有 HTML 静态文件（public/ 目录下）
                $hasStaticFiles = false;
                $publicDir = $directory . '/public';
                if (File::exists($publicDir)) {
                    $publicFiles = File::allFiles($publicDir);
                    foreach ($publicFiles as $pf) {
                        if (in_array(strtolower($pf->getExtension()), ['html', 'htm'])) {
                            $hasStaticFiles = true;
                            break;
                        }
                    }
                }
                $allPlugins[] = [
                    'code' => $config['code'],
                    'name' => $config['name'],
                    'version' => $config['version'],
                    'description' => $config['description'],
                    'author' => $config['author'],
                    'type' => $pluginType,
                    'is_installed' => $installed,
                    'is_enabled' => $installed ? $installedPlugins[$code]['is_enabled'] : false,
                    'is_protected' => $isCore,
                    'can_be_deleted' => !$isCore,
                    'has_config' => $hasConfig,
                    'need_upgrade' => $needUpgrade,
                    'has_readme' => $readmeFile !== null,
                    'has_static_files' => $hasStaticFiles,
                    'admin_menus' => $config['admin_menus'] ?? null,
                    'admin_crud' => $config['admin_crud'] ?? null,
                    'actions' => $installed ? $this->getPluginActions($code) : [],
                ];
            }
        }

        $total = count($allPlugins);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($allPlugins, $offset, $pageSize);

        return response()->json([
            'data' => $items,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $pageSize,
            'last_page' => max(1, (int) ceil($total / $pageSize)),
        ]);
    }

    /**
     * 安装插件
     */
    public function install(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->install($request->input('code'));
            return response()->json([
                'message' => '插件安装成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件安装失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 卸载插件
     */
    public function uninstall(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');
        $plugin = Plugin::where('code', $code)->first();
        if ($plugin && $plugin->is_enabled) {
            return response()->json([
                'message' => '请先禁用插件后再卸载'
            ], 400);
        }

        try {
            $this->pluginManager->uninstall($code);
            return response()->json([
                'message' => '插件卸载成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件卸载失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 升级插件
     */
    public function upgrade(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);
        try {
            $this->pluginManager->update($request->input('code'));
            return response()->json([
                'message' => '插件升级成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件升级失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 启用插件
     */
    public function enable(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->enable($request->input('code'));
            return response()->json([
                'message' => '插件启用成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件启用失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 禁用插件
     */
    public function disable(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $this->pluginManager->disable($request->input('code'));
        return response()->json([
            'message' => '插件禁用成功'
        ]);

    }

    /**
     * 获取插件配置
     */
    public function getConfig(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $config = $this->configService->getConfig($request->input('code'));
            return response()->json([
                'data' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取配置失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 更新插件配置
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'config' => 'required|array'
        ]);

        try {
            $this->configService->updateConfig(
                $request->input('code'),
                $request->input('config')
            );

            return response()->json([
                'message' => '配置更新成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '配置更新失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 上传插件
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:zip',
                'max:10240', // 最大10MB
            ]
        ], [
            'file.required' => '请选择插件包文件',
            'file.file' => '无效的文件类型',
            'file.mimes' => '插件包必须是zip格式',
            'file.max' => '插件包大小不能超过10MB'
        ]);

        try {
            $this->pluginManager->upload($request->file('file'));
            return response()->json([
                'message' => '插件上传成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件上传失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 删除插件
     */
    public function delete(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');

        // 检查是否为核心插件
        if ($this->pluginManager->isCorePlugin($code)) {
            return response()->json([
                'message' => '该插件为系统核心插件，不允许删除'
            ], 403);
        }

        try {
            $this->pluginManager->delete($code);
            return response()->json([
                'message' => '插件删除成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件删除失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 获取插件注册的动作按钮
     */
    protected function getPluginActions(string $code): array
    {
        $allActions = HookManager::filter('plugin.actions', []);
        return $allActions[$code] ?? [];
    }

    /**
     * 执行插件动作
     */
    public function executeAction(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'action' => 'required|string',
        ]);

        $code = $request->input('code');
        $actionName = $request->input('action');

        $plugin = Plugin::where('code', $code)->first();
        if (!$plugin || !$plugin->is_enabled) {
            return response()->json(['message' => '插件未启用'], 400);
        }

        $result = HookManager::filter(
            'plugin.action.execute.' . $code . '.' . $actionName,
            [],
            $request->input('params', [])
        );

        return response()->json([
            'message' => $result['message'] ?? '执行成功',
            'data' => $result,
        ]);
    }

    /**
     * 获取插件文档（README）
     */
    public function getReadme(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');

        foreach ($this->pluginManager->getPluginPaths() as $pluginPath) {
            if (!File::exists($pluginPath)) {
                continue;
            }
            $directories = File::directories($pluginPath);
            foreach ($directories as $directory) {
                $configFile = $directory . '/config.json';
                if (!File::exists($configFile)) {
                    continue;
                }
                $config = json_decode(File::get($configFile), true);
                if (!$config || !isset($config['code']) || $config['code'] !== $code) {
                    continue;
                }
                $readmeFile = collect(['README.md', 'readme.md'])
                    ->map(fn($f) => $directory . '/' . $f)
                    ->first(fn($path) => File::exists($path));
                $content = $readmeFile ? File::get($readmeFile) : '';
                return response()->json(['data' => $content]);
            }
        }

        return response()->json(['data' => '']);
    }

    /**
     * 获取插件的已发布静态文件列表
     */
    public function staticFiles(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');
        $files = $this->pluginManager->getStaticFiles($code);

        return response()->json([
            'data' => $files
        ]);
    }
}