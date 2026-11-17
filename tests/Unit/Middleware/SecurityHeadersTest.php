<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_sets_base_security_headers(): void
    {
        $response = $this->handle(Request::create('/'));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('0', $response->headers->get('X-XSS-Protection'));
    }

    public function test_sets_csp_with_dangerous_directives_tightened(): void
    {
        $csp = (string) $this->handle(Request::create('/'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_can_be_disabled_via_config(): void
    {
        config()->set('security.headers.enabled', false);
        config()->set('security.csp.enabled', false);

        $response = $this->handle(Request::create('/'));

        $this->assertNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    private function handle(Request $request): Response
    {
        return (new SecurityHeaders())->handle($request, fn () => new Response('ok'));
    }
}
