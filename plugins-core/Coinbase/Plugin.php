<?php

namespace Plugin\Coinbase;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['Coinbase'] = [
                    'name' => $this->getConfig('display_name', 'Coinbase'),
                    'icon' => $this->getConfig('icon', '🪙'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'coinbase_url' => [
                'label' => '接口地址',
                'type' => 'string',
                'required' => true,
                'description' => 'Coinbase Commerce API地址'
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Coinbase Commerce API密钥'
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook签名验证密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'name' => '订阅套餐',
            'description' => '订单号 ' . $order['trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['total_amount'] / 100),
                'currency' => 'CNY'
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = http_build_query($params);
        $ret_raw = $this->curlPost($this->getConfig('coinbase_url'), $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['data']['hosted_url'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }

    public function notify($params): array
    {
        $payload = trim(request()->getContent());
        $json_param = json_decode($payload, true);

        $headerName = 'X-Cc-Webhook-Signature';
        $headers = getallheaders();
        $signatureHeader = isset($headers[$headerName]) ? $headers[$headerName] : '';
        $computedSignature = \hash_hmac('sha256', $payload, $this->getConfig('coinbase_webhook_key'));

        if (!$this->hashEqual($signatureHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        // 仅确认/解决类事件可入账；created/pending/failed 等不得开通
        $eventType = $json_param['event']['type'] ?? '';
        $allowedTypes = [
            'charge:confirmed',
            'charge:resolved',
            'charge:completed',
        ];
        if (!in_array($eventType, $allowedTypes, true)) {
            throw new ApiException('Unsupported Coinbase event type: ' . $eventType, 400);
        }

        $out_trade_no = $json_param['event']['data']['metadata']['outTradeNo'] ?? null;
        $pay_trade_no = $json_param['event']['id'] ?? null;
        if (!$out_trade_no || !$pay_trade_no) {
            throw new ApiException('Coinbase payload missing trade reference', 400);
        }

        $result = [
            'trade_no' => $out_trade_no,
            'callback_no' => $pay_trade_no,
        ];

        // 从 local 定价提取实付（元）→ 分，供 PaymentController 拦截少付
        $localAmount = $json_param['event']['data']['pricing']['local']['amount']
            ?? $json_param['event']['data']['local_price']['amount']
            ?? null;
        if ($localAmount !== null && is_numeric($localAmount)) {
            $result['paid_amount'] = (int) round(((float) $localAmount) * 100);
        }

        return $result;
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('X-CC-Api-Key:' . $this->getConfig('coinbase_api_key'), 'X-CC-Version: 2018-03-22')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 