<?php

namespace Tests\Unit\Services;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 主题名会被拼进 base_path()/public_path()，必须阻止路径遍历导致的任意目录删除/读取。
 */
class ThemeServicePathTraversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_malicious_theme_names(): void
    {
        $service = new ThemeService();

        foreach (['', '.', '..', 'a/b', 'a\\b', '../../x', 'x..y', "a\0b"] as $bad) {
            $this->assertFalse($service->isValidThemeName($bad), "should reject: {$bad}");
        }
    }

    public function test_accepts_normal_theme_names(): void
    {
        $service = new ThemeService();

        foreach (['Fboard', 'v2board', 'my-theme_1', 'Theme123'] as $good) {
            $this->assertTrue($service->isValidThemeName($good), "should accept: {$good}");
        }
    }

    public function test_get_theme_path_refuses_traversal_even_when_target_exists(): void
    {
        $service = new ThemeService();
        $unique = 'theme-pt-' . uniqid();
        // base_path('/storage/theme/../' . $unique) 解析后即 base_path('storage/' . $unique)
        $target = base_path('storage/' . $unique);
        File::ensureDirectoryExists($target);
        File::put($target . '/marker.txt', 'keep');

        try {
            $this->assertTrue(File::exists($target));
            $this->assertNull($service->getThemePath('../' . $unique));
            $this->assertFalse($service->exists('../' . $unique));
        } finally {
            File::deleteDirectory($target);
        }
    }

    public function test_delete_refuses_traversal_and_leaves_target_intact(): void
    {
        $service = new ThemeService();
        $unique = 'theme-del-' . uniqid();
        $target = base_path('storage/' . $unique);
        File::ensureDirectoryExists($target);
        File::put($target . '/marker.txt', 'keep');

        try {
            $thrown = false;
            try {
                $service->delete('../' . $unique);
            } catch (\Exception $e) {
                $thrown = true;
                $this->assertStringContainsString('Invalid theme name', $e->getMessage());
            }

            $this->assertTrue($thrown, 'delete() must reject traversal names');
            $this->assertTrue(File::exists($target . '/marker.txt'), 'target must not be deleted');
        } finally {
            File::deleteDirectory($target);
        }
    }

    public function test_cleanup_refuses_traversal_name(): void
    {
        $service = new ThemeService();

        // 不应抛异常，也不应删除 public/（'..' 会让旧实现解析到 public 目录）
        $service->cleanupThemeFiles('..');

        $this->assertTrue(File::exists(public_path()));
    }
}
