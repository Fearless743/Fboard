<?php

namespace Tests\Unit\Utils;

use App\Utils\Helper;
use PHPUnit\Framework\TestCase;

class HelperSanitizeHtmlTest extends TestCase
{
    public function test_strips_script_and_event_handlers(): void
    {
        $out = Helper::sanitizeHtml('<p onclick="alert(1)" onmouseover="bad()">你好<script>alert(2)</script><b>粗体</b></p>');

        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringContainsString('你好', $out);
        $this->assertStringContainsString('<b>粗体</b>', $out);
    }

    public function test_rejects_dangerous_urls_but_keeps_safe_ones(): void
    {
        $html = '<a href="javascript:alert(1)">x</a>'
            . '<a href="java&#x0A;script:alert(1)">y</a>'
            . '<img src="vbscript:msgbox(1)">'
            . '<a href="/safe/path">ok</a>'
            . '<a href="https://example.com">ext</a>';

        $out = Helper::sanitizeHtml($html);

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('java', $out);
        $this->assertStringNotContainsString('vbscript:', $out);
        $this->assertStringContainsString('href="/safe/path"', $out);
        $this->assertStringContainsString('href="https://example.com"', $out);
    }

    public function test_forces_noopener_on_blank_target(): void
    {
        $out = Helper::sanitizeHtml('<a href="https://example.com" target="_blank">x</a>');

        $this->assertStringContainsString('noopener', $out);
        $this->assertStringContainsString('noreferrer', $out);
    }

    public function test_drops_iframes_forms_and_css_expression(): void
    {
        $html = '<iframe src="https://evil.example"></iframe>'
            . '<form action="/x"><input name="a"><button>go</button></form>'
            . '<div style="width:expression(alert(1))">内容</div>';

        $out = Helper::sanitizeHtml($html);

        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringNotContainsString('form', $out);
        $this->assertStringNotContainsString('input', $out);
        $this->assertStringNotContainsString('button', $out);
        $this->assertStringNotContainsString('expression', $out);
        $this->assertStringContainsString('内容', $out);
    }

    public function test_keeps_quill_formatting_and_raster_image(): void
    {
        $html = '<h2 class="ql-align-center">标题</h2>'
            . '<ul><li>列表项</li></ul>'
            . '<img src="/upload/a.png" alt="图片">'
            . '<br><code>inline</code>';

        $out = Helper::sanitizeHtml($html);

        foreach (['ql-align-center', '<li>列表项</li>', 'src="/upload/a.png"', 'alt="图片"', '<br', '<code>inline</code>'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function test_allows_raster_data_image_but_rejects_svg_data_image(): void
    {
        $raster = Helper::sanitizeHtml('<img src="data:image/png;base64,iVBORw0KGgo=">');
        $svg = Helper::sanitizeHtml('<img src="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==">');

        $this->assertStringContainsString('data:image/png;base64', $raster);
        $this->assertStringNotContainsString('data:image/svg+xml', $svg);
    }

    public function test_removes_comments_and_unknown_tags_are_unwrapped(): void
    {
        $out = Helper::sanitizeHtml('<!--[if IE]><script>bad()</script><![endif]--><custom>文本<span>保留</span></custom>');

        $this->assertStringNotContainsString('custom', $out);
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringContainsString('文本', $out);
        $this->assertStringContainsString('<span>保留</span>', $out);
    }
}
