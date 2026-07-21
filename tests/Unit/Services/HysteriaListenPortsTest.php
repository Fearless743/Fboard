<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\ServerService;
use PHPUnit\Framework\TestCase;

class HysteriaListenPortsTest extends TestCase
{
    private function serverWithPort(string|int $port): Server
    {
        $server = new Server();
        $server->port = $port;
        return $server;
    }

    public function test_single_port_returns_null(): void
    {
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort(443)));
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('443')));
    }

    public function test_valid_range_is_normalized(): void
    {
        $this->assertSame(
            '10000-10100',
            ServerService::hysteriaListenPorts($this->serverWithPort('10000-10100'))
        );
        $this->assertSame(
            '10000-10100',
            ServerService::hysteriaListenPorts($this->serverWithPort('10000 - 10100'))
        );
    }

    public function test_span_over_1024_is_rejected(): void
    {
        // 10000-20000 span = 10001 ports
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('10000-20000')));
    }

    public function test_invalid_ranges_return_null(): void
    {
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('20000-10000')));
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('0-100')));
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('abc-def')));
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('')));
    }

    public function test_max_span_boundary(): void
    {
        // 1024 ports inclusive: 1-1024
        $this->assertSame('1-1024', ServerService::hysteriaListenPorts($this->serverWithPort('1-1024')));
        // 1025 ports: reject
        $this->assertNull(ServerService::hysteriaListenPorts($this->serverWithPort('1-1025')));
    }
}
