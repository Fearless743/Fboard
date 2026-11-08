<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Plugin\CoreProtocols\ProtocolTypes;
use Tests\TestCase;

class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $extra = []): User
    {
        return User::create(array_merge([
            'email' => 'sec-' . Str::random(8) . '@example.com',
            'password' => bcrypt('secret123'),
            'uuid' => (string) Str::uuid(),
            'token' => md5(Str::random(16)),
        ], $extra));
    }

    private function makeNode(): Server
    {
        return Server::create([
            'name' => 'sec-node',
            'type' => ProtocolTypes::VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'enabled' => true,
        ]);
    }

    public function test_sanitize_redirect(): void
    {
        $this->assertSame('dashboard', Helper::sanitizeRedirect('https://evil.com/steal'));
        $this->assertSame('dashboard', Helper::sanitizeRedirect('//evil.com/x'));
        $this->assertSame('dashboard', Helper::sanitizeRedirect('/\\evil.com'));
        $this->assertSame('dashboard', Helper::sanitizeRedirect('javascript:alert(1)'));
        $this->assertSame('dashboard', Helper::sanitizeRedirect(''));
        $this->assertSame('dashboard', Helper::sanitizeRedirect(null));
        $this->assertSame('dashboard', Helper::sanitizeRedirect(['x' => 1]));
        $this->assertSame('/dashboard', Helper::sanitizeRedirect('/dashboard'));
        $this->assertSame('dashboard', Helper::sanitizeRedirect('dashboard'));
    }

    public function test_process_traffic_drops_single_entry_over_100tb(): void
    {
        Queue::fake();
        $user = $this->makeUser(['u' => 0, 'd' => 0, 'transfer_enable' => 1000]);
        $node = $this->makeNode();

        $huge = 150 * 1024 * 1024 * 1024 * 1024; // 150TB
        ServerService::processTraffic($node, [(string) $user->id => [$huge, 0]]);

        Queue::assertNotPushed(\App\Jobs\TrafficFetchJob::class);
        $this->assertSame(0, (int) $user->refresh()->u);
    }

    public function test_process_traffic_truncates_to_20000_entries(): void
    {
        Queue::fake();
        $node = $this->makeNode();
        $traffic = [];
        for ($i = 1; $i <= 20005; $i++) {
            $traffic[(string) ($i + 500000)] = [1, 1];
        }
        ServerService::processTraffic($node, $traffic);

        Queue::assertPushed(\App\Jobs\TrafficFetchJob::class, function ($job) {
            return true;
        });
        // 数据按 200 条 chunk 分发：汇总所有 Job 的条目数应恰好等于截断上限 20000。
        $total = 0;
        foreach (Queue::pushed(\App\Jobs\TrafficFetchJob::class) as $job) {
            $ref = new \ReflectionProperty($job, 'data');
            $ref->setAccessible(true);
            $total += count($ref->getValue($job));
        }
        $this->assertSame(20000, $total);
    }

    public function test_process_online_filters_non_positive_uid(): void
    {
        $node = $this->makeNode();
        ServerService::processOnline($node, [0 => 3, -5 => 2, 123456 => 4]);

        $this->assertSame(1, Cache::get(\App\Utils\CacheKey::get(
            'SERVER_' . strtoupper($node->type) . '_ONLINE_USER', $node->id
        )));
        $this->assertNull(Cache::get(\App\Utils\CacheKey::get(
            "USER_ONLINE_CONN_{$node->type}_{$node->id}", 0
        )));
        $this->assertSame(4, Cache::get(\App\Utils\CacheKey::get(
            "USER_ONLINE_CONN_{$node->type}_{$node->id}", 123456
        )));
    }

    public function test_telegram_webhook_rejects_when_token_unconfigured(): void
    {
        // RefreshDatabase 下未配置 telegram_bot_token，必须 401（防 md5('') 公开常量）。
        $res = $this->postJson('/api/v1/guest/telegram/webhook?access_token=' . md5(''), []);
        $res->assertStatus(401);
    }

    public function test_token2login_redirect_whitelist(): void
    {
        $res = $this->get('/api/v1/passport/auth/token2Login?token=abc123&redirect=' . urlencode('https://evil.com/phish'));
        $res->assertRedirect();
        $location = $res->headers->get('Location');
        $this->assertStringContainsString('redirect=dashboard', $location);
        $this->assertStringNotContainsString('evil.com', $location);
    }

    private function securePrefix(): string
    {
        return admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
    }

    public function test_admin_update_privilege_escalation_blocked(): void
    {
        $super = $this->makeUser(['is_admin' => true]); // id=1
        $staff = $this->makeUser(['is_admin' => true]); // id=2
        $target = $this->makeUser();
        $prefix = $this->securePrefix();

        $this->assertSame(1, (int) $super->id);

        $res = $this->actingAs($staff, 'sanctum')->postJson("/api/v2/{$prefix}/user/update", [
            'id' => $target->id,
            'is_admin' => true,
        ]);
        $res->assertStatus(403);
        $this->assertFalse((bool) $target->refresh()->is_admin);

        $res = $this->actingAs($super, 'sanctum')->postJson("/api/v2/{$prefix}/user/update", [
            'id' => $target->id,
            'is_staff' => true,
        ]);
        $res->assertOk();
        $this->assertTrue((bool) $target->refresh()->is_staff);
    }

    public function test_config_fetch_masks_secrets(): void
    {
        $super = $this->makeUser(['is_admin' => true]); // id=1
        $staff = $this->makeUser(['is_admin' => true]); // id=2
        admin_setting(['server_token' => 's3cr3t-node-token']);
        admin_setting(['email_password' => 'mail-pass']);
        admin_setting(['telegram_bot_token' => 'tg-token']);
        $prefix = $this->securePrefix();

        $res = $this->actingAs($staff, 'sanctum')->getJson("/api/v2/{$prefix}/config/fetch?key=server");
        $res->assertOk();
        $this->assertSame('******', $res->json('data.server.server_token'));

        // 超管默认同样打码
        $res = $this->actingAs($super, 'sanctum')->getJson("/api/v2/{$prefix}/config/fetch?key=server");
        $this->assertSame('******', $res->json('data.server.server_token'));

        // 超管 + show_secrets=1 可见明文；普通管理员带参也看不到
        $res = $this->actingAs($super, 'sanctum')->getJson("/api/v2/{$prefix}/config/fetch?key=server&show_secrets=1");
        $this->assertSame('s3cr3t-node-token', $res->json('data.server.server_token'));

        $res = $this->actingAs($staff, 'sanctum')->getJson("/api/v2/{$prefix}/config/fetch?key=server&show_secrets=1");
        $this->assertSame('******', $res->json('data.server.server_token'));
    }

    public function test_reset_security_revokes_sanctum_tokens(): void
    {
        $user = $this->makeUser();
        $user->createToken('test-session');
        $this->assertSame(1, $user->tokens()->count());

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/resetSecurity');
        $res->assertOk();
        $this->assertSame(0, $user->refresh()->tokens()->count());
    }
}
