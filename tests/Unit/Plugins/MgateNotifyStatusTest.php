<?php

namespace Tests\Unit\Plugins;

use Plugin\Mgate\Plugin as MgatePlugin;
use Tests\TestCase;

/**
 * Mgate 回调若带 status/trade_status，非成功态不得入账。
 */
class MgateNotifyStatusTest extends TestCase
{
    public function test_pending_status_is_rejected(): void
    {
        $plugin = new MgatePlugin('Mgate');
        $plugin->setConfig([
            'mgate_app_secret' => 'secret',
            'mgate_app_id' => 'app',
            'mgate_url' => 'https://x',
        ]);

        $params = [
            'out_trade_no' => 'T1',
            'trade_no' => 'G1',
            'total_amount' => 1000,
            'status' => 'pending',
        ];
        ksort($params);
        $params['sign'] = md5(http_build_query($params) . 'secret');

        $this->assertFalse($plugin->notify($params));
    }

    public function test_paid_status_accepted_with_paid_amount(): void
    {
        $plugin = new MgatePlugin('Mgate');
        $plugin->setConfig([
            'mgate_app_secret' => 'secret',
            'mgate_app_id' => 'app',
            'mgate_url' => 'https://x',
        ]);

        $params = [
            'out_trade_no' => 'T2',
            'trade_no' => 'G2',
            'total_amount' => 1000,
            'status' => 'paid',
        ];
        ksort($params);
        $params['sign'] = md5(http_build_query($params) . 'secret');

        $result = $plugin->notify($params);
        $this->assertIsArray($result);
        $this->assertSame(1000, $result['paid_amount']);
        $this->assertSame('T2', $result['trade_no']);
    }

    public function test_missing_status_still_accepted_for_compat(): void
    {
        $plugin = new MgatePlugin('Mgate');
        $plugin->setConfig([
            'mgate_app_secret' => 'secret',
            'mgate_app_id' => 'app',
            'mgate_url' => 'https://x',
        ]);

        $params = [
            'out_trade_no' => 'T3',
            'trade_no' => 'G3',
            'total_amount' => 500,
        ];
        ksort($params);
        $params['sign'] = md5(http_build_query($params) . 'secret');

        $result = $plugin->notify($params);
        $this->assertIsArray($result);
        $this->assertSame(500, $result['paid_amount']);
    }
}
