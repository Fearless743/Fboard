<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ServerRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        $routes = ServerRoute::get();
        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'remarks' => 'required',
            'match' => 'required|array',
            'action' => 'required|in:block,direct,dns,proxy',
            'action_value' => 'nullable'
        ], [
            'remarks.required' => '备注不能为空',
            'match.required' => '匹配值不能为空',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误'
        ]);
        $params['match'] = array_filter($params['match']);
        try {
            if ($request->input('id')) {
                $route = ServerRoute::find($request->input('id'));
                if (!$route) {
                    throw new ApiException('路由不存在');
                }
                $route->update($params);
            } else {
                ServerRoute::create($params);
            }
            return $this->success(true);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, $request->input('id') ? '保存失败' : '创建失败']);
        }
    }

    public function drop(Request $request)
    {
        $route = ServerRoute::find($request->input('id'));
        if (!$route) throw new ApiException('路由不存在');
        if (!$route->delete()) throw new ApiException('删除失败');
        return [
            'data' => true
        ];
    }
}
