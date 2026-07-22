<?php

namespace Tests\Unit\Plugins;

use Plugin\Epay\Plugin;
use Tests\TestCase;

/**
 * 易支付回调：签名合法 + TRADE_SUCCESS 时，仍须校验回调金额，
 * 否则少付即可入账。
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

    public function test_notify_accepts_matching_money_when_expected_provided(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-1',
            'out_trade_no' => 'ORDER-100',
            'type' => 'alipay',
            'name' => 'ORDER-100',
            'money' => '100.00', // 100 元 = 10000 分
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params, 'test-secret');
        $params['sign_type'] = 'MD5';

        // 通过可选 expected 校验（插件接口扩展 / 控制器传入）
        $result = $plugin->notify($params, 10000);

        $this->assertIsArray($result);
        $this->assertSame('ORDER-100', $result['trade_no']);
        $this->assertSame('gw-1', $result['callback_no']);
    }

    public function test_notify_rejects_underpayment_when_expected_cents_given(): void
    {
        $plugin = $this->makePlugin();
        $params = [
            'pid' => '1000',
            'trade_no' => 'gw-2',
            'out_trade_no' => 'ORDER-101',
            'type' => 'alipay',
            'name' => 'ORDER-101',
            'money' => '0.01', // 少付
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = $this->sign($params, 'test-secret');
        $params['sign_type'] = 'MD5';

        $result = $plugin->notify($params, 10000);

        $this->assertFalse(
            $result,
            '签名合法但 money 少于订单应付时必须拒绝入账'
        );
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

        $this->assertFalse($plugin->notify($params, 10000));
    }
}
