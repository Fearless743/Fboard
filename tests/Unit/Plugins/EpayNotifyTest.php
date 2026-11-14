<?php

namespace Tests\Unit\Plugins;

use Plugin\Epay\Plugin;
use Tests\TestCase;

/**
 * 易支付回调：签名合法 + TRADE_SUCCESS 即视为成功，不校验回调金额。
 */
class EpayNotifyTest extends TestCase
{
    private function makePlugin(string $key = 'test-secret'): Plugin
    {
        $plugin = new Plugin('Epay');
        $plugin->setConfig([
            'url' => 'https://pay.example.com',
            'pid' => '1000',
            'key' => $key,
            'enabled' => true,
        ]);
        return $plugin;
    }

    private function sign(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $key;
        return md5($str);
    }

    public function test_notify_accepts_valid_signature_and_returns_trade_numbers(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-1',
            'out_trade_no' => 'ORDER-100',
            'type' => 'alipay',
            'name' => 'ORDER-100',
            'money' => '100.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params, 'test-secret');
        $params['sign_type'] = 'MD5';

        $result = $plugin->notify($params);

        $this->assertIsArray($result);
        $this->assertSame('ORDER-100', $result['trade_no']);
        $this->assertSame('gw-1', $result['callback_no']);
        // 不再返回 paid_amount，避免 PaymentController 做金额比对
        $this->assertArrayNotHasKey('paid_amount', $result);
    }

    public function test_notify_ignores_underpayment_amount(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-2',
            'out_trade_no' => 'ORDER-101',
            'type' => 'alipay',
            'name' => 'ORDER-101',
            'money' => '0.01', // 少付也不再拒绝
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params, 'test-secret');
        $params['sign_type'] = 'MD5';

        $result = $plugin->notify($params);

        $this->assertIsArray($result);
        $this->assertSame('ORDER-101', $result['trade_no']);
    }

    public function test_notify_accepts_missing_money(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-4',
            'out_trade_no' => 'ORDER-103',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params, 'test-secret');
        $params['sign_type'] = 'MD5';

        $this->assertIsArray($plugin->notify($params));
    }

    public function test_notify_rejects_invalid_signature(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-5',
            'out_trade_no' => 'ORDER-104',
            'money' => '100.00',
            'trade_status' => 'TRADE_SUCCESS',
            'sign' => 'bad-sign',
            'sign_type' => 'MD5',
        ];

        $this->assertFalse($plugin->notify($params));
    }

    public function test_notify_rejects_non_success_status(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-3',
            'out_trade_no' => 'ORDER-102',
            'money' => '100.00',
            'trade_status' => 'TRADE_PENDING',
        ];
        $params['sign'] = $this->sign($params, 'test-secret');
        $params['sign_type'] = 'MD5';

        $this->assertFalse($plugin->notify($params));
    }
}
