<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Plugin\Concerns\InstallsExtensionDemoPlugin;
use Tests\TestCase;

class AdminUiExtensionEndpointTest extends TestCase
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

    private function adminUser(): User
    {
        return User::create([
            'email' => 'admin@test.local',
            'password' => bcrypt('secret'),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => substr(md5((string) \Illuminate\Support\Str::uuid()), 0, 32),
            'is_admin' => true,
            'banned' => false,
        ]);
    }

    private function securePrefix(): string
    {
        return admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    private function enableDemoPlugin(): void
    {
        Plugin::create([
            'code' => 'extension_demo',
            'name' => 'Extension Demo',
            'description' => 'demo',
            'version' => '1.0.0',
            'author' => 'Fboard',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => true,
        ]);
    }

    public function test_ui_extensions_endpoint_returns_manifest(): void
    {
        $this->enableDemoPlugin();
        $prefix = $this->securePrefix();

        $res = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("/api/v2/{$prefix}/plugin/ui");

        $res->assertOk();
        $res->assertJsonFragment(['id' => 'extension_demo:demo_banner']);
        $res->assertJsonFragment(['id' => 'extension_demo:demo_sync']);
        $res->assertJsonFragment(['id' => 'extension_demo:demo_header_badge']);
    }

    public function test_ui_navigation_endpoint_returns_menus_and_pages(): void
    {
        $this->enableDemoPlugin();
        $prefix = $this->securePrefix();

        $res = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("/api/v2/{$prefix}/plugin/ui/nav");

        $res->assertOk();
        $res->assertJsonPath('data.pages.0.url', '/plugins/extension_demo/page.html');
        $res->assertJsonPath('data.menus.0.path', 'extension-demo');
        $res->assertJsonStructure(['data' => ['menus', 'pages', 'i18n']]);
    }

    public function test_endpoints_require_admin(): void
    {
        $prefix = $this->securePrefix();

        $this->getJson("/api/v2/{$prefix}/plugin/ui")->assertStatus(403);
        $this->getJson("/api/v2/{$prefix}/plugin/ui/nav")->assertStatus(403);
    }
}
