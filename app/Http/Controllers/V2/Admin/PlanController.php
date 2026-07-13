<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        return response([
            'data' => $plans->items(),
            'total' => $plans->total(),
            'current_page' => $plans->currentPage(),
            'per_page' => $plans->perPage(),
            'last_page' => $plans->lastPage(),
        ]);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();

        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }

            HookManager::call('admin.plan.save.before', [
                'plan' => $plan,
                'params' => $params,
                'request' => $request,
            ]);

            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();

                HookManager::call('admin.plan.save.after', [
                    'plan' => $plan,
                    'params' => $params,
                    'request' => $request,
                ]);

                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        HookManager::call('admin.plan.save.before', [
            'plan' => null,
            'params' => $params,
            'request' => $request,
        ]);

        $plan = Plan::create($params);
        if (!$plan) {
            return $this->fail([500, '创建失败']);
        }

        HookManager::call('admin.plan.save.after', [
            'plan' => $plan,
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        HookManager::call('admin.plan.drop.before', [
            'plan' => $plan,
            'request' => $request,
        ]);

        $result = $plan->delete();

        HookManager::call('admin.plan.drop.after', [
            'plan' => $plan,
            'request' => $request,
        ]);

        return $this->success($result);
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        HookManager::call('admin.plan.update.before', [
            'plan' => $plan,
            'params' => $updateData,
            'request' => $request,
        ]);

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.plan.update.after', [
            'plan' => $plan,
            'params' => $updateData,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        HookManager::call('admin.plan.sort.before', [
            'params' => $params,
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.plan.sort.after', [
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }
}
