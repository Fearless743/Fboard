<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\NetworkSettingsTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NetworkSettingsTemplateController extends Controller
{
    public function fetch(Request $request)
    {
        $query = NetworkSettingsTemplate::query()->orderByDesc("id");

        $network = $request->query("network");
        if ($network !== null && $network !== "") {
            // 当前传输协议 + 通用模板
            $query->where(function ($q) use ($network) {
                $q->whereNull("network")
                    ->orWhere("network", "")
                    ->orWhere("network", $network);
            });
        }

        return [
            "data" => $query->get(),
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate(
            [
                "id" => "nullable|integer",
                "name" => "required|string|max:128",
                "description" => "nullable|string|max:255",
                "network" => "nullable|string|max:32",
                "settings" => "required|array",
            ],
            [
                "name.required" => "模板名称不能为空",
                "settings.required" => "网络设置不能为空",
                "settings.array" => "网络设置必须是对象",
            ],
        );

        // 禁止数组形式 settings
        if (array_is_list($params["settings"])) {
            return $this->fail([400, "网络设置必须是 JSON 对象"]);
        }

        $params["network"] = $params["network"] ?? null;
        if ($params["network"] === "") {
            $params["network"] = null;
        }

        try {
            if (!empty($params["id"])) {
                $template = NetworkSettingsTemplate::find($params["id"]);
                if (!$template) {
                    return $this->fail([400202, "模板不存在"]);
                }
                unset($params["id"]);
                $template->update($params);
                return $this->success($template->fresh());
            }

            unset($params["id"]);
            $template = NetworkSettingsTemplate::create($params);
            return $this->success($template);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "保存失败"]);
        }
    }

    public function drop(Request $request)
    {
        $request->validate([
            "id" => "required|integer",
        ]);

        $template = NetworkSettingsTemplate::find($request->input("id"));
        if (!$template) {
            throw new ApiException("模板不存在");
        }
        if (!$template->delete()) {
            throw new ApiException("删除失败");
        }

        return $this->success(true);
    }
}
