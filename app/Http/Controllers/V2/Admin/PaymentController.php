<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function getPaymentMethods()
    {
        $methods = [];

        $pluginMethods = PaymentService::getAllPaymentMethodNames();
        $methods = array_merge($methods, $pluginMethods);

        return $this->success(array_unique($methods));
    }

    public function fetch(Request $request)
    {
        $query = Payment::query();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('payment', 'like', $like);
            });
        }

        $payments = $query->orderBy('sort', 'ASC')->get()->makeVisible('config');
        foreach ($payments as $k => $v) {
            $notifyUrl = url("/api/v1/guest/payment/notify/{$v->payment}/{$v->uuid}");
            if ($v->notify_domain) {
                $parseUrl = parse_url($notifyUrl);
                $notifyUrl = $v->notify_domain . $parseUrl['path'];
            }
            $payments[$k]['notify_url'] = $notifyUrl;
        }
        return $this->success($payments);
    }

    public function getPaymentForm(Request $request)
    {
        try {
            $paymentService = new PaymentService($request->input('payment'), $request->input('id'));
            return $this->success(collect($paymentService->form()));
        } catch (\Exception $e) {
            return $this->fail([400, '支付方式不存在或未启用']);
        }
    }

    public function show(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment)
            return $this->fail([400202, '支付方式不存在']);

        $originalEnable = $payment->enable;
        $payment->enable = !$payment->enable;
        if (!$payment->save())
            return $this->fail([500, '保存失败']);

        HookManager::call('admin.payment.show.toggle', [
            'payment' => $payment,
            'original_enable' => $originalEnable,
            'new_enable' => $payment->enable,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function save(Request $request)
    {
        if (!admin_setting('app_url')) {
            return $this->fail([400, '请在站点配置中配置站点地址']);
        }
        $params = $request->validate([
            'name' => 'required',
            'icon' => 'nullable',
            'payment' => 'required',
            'config' => 'required',
            'notify_domain' => 'nullable|url',
            'handling_fee_fixed' => 'nullable|integer',
            'handling_fee_percent' => 'nullable|numeric|between:0,100'
        ], [
            'name.required' => '显示名称不能为空',
            'payment.required' => '网关参数不能为空',
            'config.required' => '配置参数不能为空',
            'notify_domain.url' => '自定义通知域名格式有误',
            'handling_fee_fixed.integer' => '固定手续费格式有误',
            'handling_fee_percent.between' => '百分比手续费范围须在0-100之间'
        ]);

        HookManager::call('admin.payment.save.before', [
            'params' => $params,
            'request' => $request,
        ]);

        if ($request->input('id')) {
            $payment = Payment::find($request->input('id'));
            if (!$payment)
                return $this->fail([400202, '支付方式不存在']);
            try {
                $payment->update($params);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }

            HookManager::call('admin.payment.save.after', [
                'payment' => $payment,
                'params' => $params,
                'request' => $request,
            ]);

            return $this->success(true);
        }
        $params['uuid'] = Helper::randomChar(8);
        $payment = Payment::create($params);
        if (!$payment) {
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.payment.save.after', [
            'payment' => $payment,
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment)
            return $this->fail([400202, '支付方式不存在']);

        HookManager::call('admin.payment.drop.before', [
            'payment' => $payment,
            'request' => $request,
        ]);

        $result = $payment->delete();

        HookManager::call('admin.payment.drop.after', [
            'payment' => $payment,
            'request' => $request,
        ]);

        return $this->success($result);
    }

    public function copy(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment) {
            return $this->fail([400202, '支付方式不存在']);
        }

        $copied = $payment->replicate();
        $copied->uuid = Helper::randomChar(8);
        $copied->enable = false;
        if (!$copied->save()) {
            return $this->fail([500, '复制失败']);
        }

        HookManager::call('admin.payment.copy.after', [
            'payment' => $copied,
            'source' => $payment,
            'request' => $request,
        ]);

        return $this->success(true);
    }


    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);

        HookManager::call('admin.payment.sort.before', [
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                if (!Payment::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.payment.sort.after', [
            'request' => $request,
        ]);

        return $this->success(true);
    }
}
