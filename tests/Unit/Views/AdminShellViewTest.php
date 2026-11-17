<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class AdminShellViewTest extends TestCase
{
    public function test_admin_shell_renders_settings_as_valid_json(): void
    {
        $html = view('admin', [
            'title' => 'Fboard',
            'version' => '1.0.0',
            'logo' => '',
            'secure_path' => 'admin',
        ])->render();

        $this->assertStringContainsString(
            '{"base_url":"\\/","title":"Fboard","version":"1.0.0","logo":"","secure_path":"admin"}',
            $html
        );
    }

    public function test_admin_shell_escapes_settings_for_inline_script(): void
    {
        $html = view('admin', [
            'title' => '</script><script>alert(1)</script>',
            'version' => '1.0.0',
            'logo' => '',
            'secure_path' => 'admin',
        ])->render();

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        $this->assertStringContainsString('\\u003C\\/script\\u003E', $html);
    }
}
