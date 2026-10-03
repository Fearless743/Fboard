<?php

namespace Tests\Feature\Plugin\Concerns;

use Illuminate\Support\Facades\File;

/**
 * 把 tests/Fixtures/ExtensionDemo 临时安装到 plugins/ExtensionDemo，
 * 供依赖该插件文件的测试使用；测试结束后自动清理。
 *
 * 示例插件不再随项目提供，测试夹具只服务于测试。
 */
trait InstallsExtensionDemoPlugin
{
    private ?string $extensionDemoPluginPath = null;
    private bool $extensionDemoPluginInstalled = false;

    protected function installExtensionDemoPlugin(): void
    {
        $this->extensionDemoPluginPath = base_path('plugins/ExtensionDemo');
        // 若本地已存在同名插件目录则不接管，避免误删用户文件
        $this->extensionDemoPluginInstalled = !File::isDirectory($this->extensionDemoPluginPath);

        File::copyDirectory(
            base_path('tests/Fixtures/ExtensionDemo'),
            $this->extensionDemoPluginPath
        );
    }

    protected function removeExtensionDemoPlugin(): void
    {
        if ($this->extensionDemoPluginInstalled && $this->extensionDemoPluginPath !== null) {
            File::deleteDirectory($this->extensionDemoPluginPath);
        }
    }
}
