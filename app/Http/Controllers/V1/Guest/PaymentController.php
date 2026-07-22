<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            // 部分插件在验签后返回 paid_amount（分）；此处再与订单应付比对，防少付入账
            if (is_array($verify) && array_key_exists('trade_no', $verify)) {
                $orderPreview = Order::where('trade_no', $verify['trade_no'])->first();
                if ($orderPreview && array_key_exists('paid_amount', $verify)) {
                    $expected = (int) $orderPreview->total_amount + (int) ($orderPreview->handling_amount ?? 0);
                    $paid = (int) $verify['paid_amount'];
                    if ($paid < $expected) {
                        Log::warning('payment notify: underpayment', [
                            'trade_no' => $verify['trade_no'],
                            'paid' => $paid,
                            'expected' => $expected,
                            'method' => $method,
                        ]);
                        HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                        return $this->fail([422, 'amount mismatch']);
                    }
                }
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo): bool
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            // 必须返回 false：JsonResponse 在布尔上下文为 truthy，会导致外层
            // notify() 仍输出 success，网关停止重试且运维无法发现丢单。
            Log::warning('payment notify: order not found', ['trade_no' => $tradeNo]);
            return false;
        }
        if ($order->status !== Order::STATUS_PENDING) {
            return true;
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }
}
