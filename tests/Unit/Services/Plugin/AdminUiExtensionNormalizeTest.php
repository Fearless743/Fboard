<?php

namespace Tests\Unit\Services\Plugin;

use App\Services\Plugin\PluginManager;
use Tests\TestCase;

class AdminUiExtensionNormalizeTest extends TestCase
{
    public function test_component_extension_with_slot(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'banner',
            'slot' => 'content.before',
            'type' => 'component',
            'component' => 'DemoBanner',
            'script' => 'admin.js',
            'style' => 'admin.css',
            'page' => 'dashboard',
        ]);

        $this->assertNotNull($item);
        $this->assertSame('demo:banner', $item['id']);
        $this->assertSame('content.before', $item['slot']);
        $this->assertSame('component', $item['type']);
        $this->assertSame('DemoBanner', $item['component']);
        $this->assertSame('/plugins/demo/admin.js', $item['script']);
        $this->assertSame('/plugins/demo/admin.css', $item['style']);
        $this->assertSame(['dashboard'], $item['page']);
    }

    public function test_button_extension(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'sync',
            'slot' => 'page.actions',
            'type' => 'button',
            'label' => '同步',
            'action' => 'sync_now',
            'params' => ['scope' => 'all'],
            'variant' => 'outline',
            'icon' => 'RefreshCw',
        ]);

        $this->assertNotNull($item);
        $this->assertSame('button', $item['type']);
        $this->assertSame('同步', $item['label']);
        $this->assertSame('sync_now', $item['action']);
        $this->assertSame(['scope' => 'all'], $item['params']);
        $this->assertSame('outline', $item['variant']);
    }

    public function test_button_without_label_is_rejected(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'sync',
            'slot' => 'page.actions',
            'type' => 'button',
        ]);

        $this->assertNull($item);
    }

    public function test_link_without_target_is_rejected(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'open',
            'slot' => 'page.actions',
            'type' => 'link',
            'label' => '打开',
        ]);

        $this->assertNull($item);
    }

    public function test_anchor_string_becomes_append(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'tip',
            'type' => 'html',
            'html' => '<div>x</div>',
            'anchor' => 'main h1',
        ]);

        $this->assertNotNull($item);
        $this->assertSame('main h1', $item['anchor']['selector']);
        $this->assertSame('append', $item['anchor']['position']);
    }

    public function test_extension_requires_slot_or_anchor(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'nowhere',
            'type' => 'html',
            'html' => '<div>x</div>',
        ]);

        $this->assertNull($item);
    }

    public function test_html_without_content_is_rejected(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'empty',
            'slot' => 'content.after',
            'type' => 'html',
        ]);

        $this->assertNull($item);
    }

    public function test_iframe_resolves_relative_url(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('demo', [
            'id' => 'frame',
            'slot' => 'content.after',
            'type' => 'iframe',
            'url' => 'page.html',
        ]);

        $this->assertNotNull($item);
        $this->assertSame('/plugins/demo/page.html', $item['url']);
    }

    public function test_invalid_plugin_code_is_rejected(): void
    {
        $item = PluginManager::normalizeAdminUiExtension('Demo Plugin', [
            'id' => 'x',
            'slot' => 'content.before',
            'type' => 'html',
            'html' => '<div>x</div>',
        ]);

        $this->assertNull($item);
    }

    public function test_menu_external_url(): void
    {
        $menu = PluginManager::normalizeAdminUiMenu('demo', [
            'path' => 'https://example.com/docs',
            'label' => 'Docs',
        ]);

        $this->assertNotNull($menu);
        $this->assertTrue($menu['external']);
        $this->assertSame('https://example.com/docs', $menu['path']);
        $this->assertSame('_blank', $menu['target']);
    }

    public function test_menu_without_label_is_rejected(): void
    {
        $menu = PluginManager::normalizeAdminUiMenu('demo', [
            'path' => 'my-plugin',
        ]);

        $this->assertNull($menu);
    }

    public function test_menu_defaults(): void
    {
        $menu = PluginManager::normalizeAdminUiMenu('demo', [
            'path' => 'my-plugin',
            'label' => 'My Plugin',
            'group' => 'nav.systemManagement',
            'icon' => 'Blocks',
        ]);

        $this->assertNotNull($menu);
        $this->assertFalse($menu['external']);
        $this->assertSame('nav.systemManagement', $menu['group']);
        $this->assertSame(100, $menu['order']);
    }

    public function test_page_iframe(): void
    {
        $page = PluginManager::normalizeAdminUiPage('demo', [
            'path' => 'my-plugin',
            'type' => 'iframe',
            'url' => 'page.html',
        ]);

        $this->assertNotNull($page);
        $this->assertSame('iframe', $page['type']);
        $this->assertSame('/plugins/demo/page.html', $page['url']);
        $this->assertSame('100%', $page['height']);
    }

    public function test_page_html_without_content_is_rejected(): void
    {
        $page = PluginManager::normalizeAdminUiPage('demo', [
            'path' => 'my-plugin',
            'type' => 'html',
        ]);

        $this->assertNull($page);
    }

    public function test_page_path_with_invalid_chars_is_rejected(): void
    {
        $page = PluginManager::normalizeAdminUiPage('demo', [
            'path' => 'my plugin!',
            'type' => 'iframe',
            'url' => 'page.html',
        ]);

        $this->assertNull($page);
    }
}
