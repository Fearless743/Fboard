<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');

/**
 * 插件静态文件路由
 * 直接从插件源码的 public/ 目录提供前端静态文件，不做磁盘复制
 * 安全限制：只放行前端安全类型（html/css/js/图片/字体等），禁脚本/可执行/服务端文件
 */
Route::get('/plugins/{pluginCode}/{path?}', function (string $pluginCode, string $path = 'index.html') {
    // 插件代码格式校验
    if (!preg_match('/^[a-z0-9_]+$/', $pluginCode)) {
        abort(404);
    }

    // 路径遍历防护
    if (str_contains($path, '..')) {
        abort(404);
    }

    // 白名单：只允许前端安全文件类型
    // 禁止: php/phtml/asp/aspx/jsp/sh/py/rb/exe/bat/cmd/cgi/pl/htaccess/sql 等
    if (!preg_match('/\.(html?|css|js|m?jsx?|ts|json|xml|svg|png|jpe?g|gif|webp|bmp|ico|avif|woff2?|ttf|eot|otf|wasm|pdf|txt|md|map)$/i', $path)) {
        abort(404);
    }

    // 查找插件目录（优先 user plugins，再 core plugins）
    $dirName = \Illuminate\Support\Str::studly($pluginCode);
    $paths = [
        base_path('plugins/' . $dirName . '/public/' . $path),
        base_path('plugins-core/' . $dirName . '/public/' . $path),
    ];

    $filePath = null;
    foreach ($paths as $p) {
        if (\Illuminate\Support\Facades\File::exists($p)) {
            $filePath = $p;
            break;
        }
    }

    if (!$filePath) {
        abort(404);
    }

    return response()->file($filePath);
})->where('path', '.*');