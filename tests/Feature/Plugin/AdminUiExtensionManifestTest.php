<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Plugin\Concerns\InstallsExtensionDemoPlugin;
use Tests\TestCase;

class AdminUiExtensionManifestTest extends TestCase
{
    use RefreshDatabase;
    use InstallsExtensionDemoPlugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installExtensionDemoPlugin();
    }

    protected function tearDown(): void
    {
        $this->removeExtensionDemoPlugin();
        parent::tearDown();
    }

    private function makeDemoPlugin(bool $enabled = true): void
    {
        Plugin::create([
            'code' => 'extension_demo',
            'name' => 'Extension Demo',
            'description' => 'demo',
            'version' => '1.0.0',
            'author' => 'Fboard',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => $enabled,
        ]);
    }

    public function test_extensions_include_declarative_and_programmatic_blocks(): void
    {
        $this->makeDemoPlugin();

        $extensions = app(PluginManager::class)->getAdminUiExtensions();
        $ids = array_column($extensions, 'id');

        $this->assertContains('extension_demo:demo_banner', $ids);
        $this->assertContains('extension_demo:demo_sync', $ids);
        $this->assertContains('extension_demo:demo_anchor', $ids);
        // 程序式注册（Plugin.php boot）
        $this->assertContains('extension_demo:demo_header_badge', $ids);

        $button = collect($extensions)->firstWhere('id', 'extension_demo:demo_sync');
        $this->assertSame('button', $button['type']);
        $this->assertSame('sync_demo', $button['action']);

        $banner = collect($extensions)->firstWhere('id', 'extension_demo:demo_banner');
        $this->assertSame('/plugins/extension_demo/admin.js', $banner['script']);
    }

    public function test_navigation_menus_pages_and_i18n(): void
    {
        $this->makeDemoPlugin();

        $nav = app(PluginManager::class)->getAdminUiNavigation();

        $this->assertCount(2, $nav['menus']);
        $this->assertCount(1, $nav['pages']);
        $this->assertArrayHasKey('zh-CN', $nav['i18n']);
        $this->assertSame('extension-demo', $nav['menus'][0]['path']);
        $this->assertSame('nav.systemManagement', $nav['menus'][0]['group']);
        $this->assertSame('/plugins/extension_demo/page.html', $nav['pages'][0]['url']);
    }

    public function test_disabled_plugin_contributes_nothing(): void
    {
        $this->makeDemoPlugin(false);

        $manager = app(PluginManager::class);

        $ids = array_column($manager->getAdminUiExtensions(), 'id');
        $this->assertNotContains('extension_demo:demo_banner', $ids);

        $nav = $manager->getAdminUiNavigation();
        $this->assertCount(0, $nav['menus']);
        $this->assertCount(0, $nav['pages']);
    }
}
