<?php

namespace Tests\Unit\WebSocket;

use App\WebSocket\WsLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WsLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWsLogState();
    }

    public function test_enabled_defaults_to_false(): void
    {
        $this->assertFalse(WsLog::enabled());
    }

    public function test_enabled_reads_setting(): void
    {
        admin_setting(['server_ws_log_enable' => true]);
        $this->resetWsLogState();

        $this->assertTrue(WsLog::enabled());

        admin_setting(['server_ws_log_enable' => false]);
        $this->resetWsLogState();

        $this->assertFalse(WsLog::enabled());
    }

    public function test_enabled_caches_within_refresh_window(): void
    {
        $this->assertFalse(WsLog::enabled());

        admin_setting(['server_ws_log_enable' => true]);
        // Within REFRESH_SECONDS the previous result is retained
        $this->assertFalse(WsLog::enabled());

        $this->resetWsLogState();
        $this->assertTrue(WsLog::enabled());
    }

    private function resetWsLogState(): void
    {
        $ref = new \ReflectionClass(WsLog::class);
        $checkedAt = $ref->getProperty('checkedAt');
        $checkedAt->setAccessible(true);
        $checkedAt->setValue(null, null);

        $enabled = $ref->getProperty('enabled');
        $enabled->setAccessible(true);
        $enabled->setValue(null, false);
    }
}
