<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CertTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CertTemplateController extends Controller
{
    public function fetch(Request $request)
    {
        $query = CertTemplate::query()->orderByDesc("id");

        $search = $request->query("search");
        if ($search !== null && $search !== "") {
            $query->where(function ($q) use ($search) {
                $q->where("name", "like", "%" . $search . "%")
                    ->orWhere("description", "like", "%" . $search . "%");
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
                "cert_content" => "required|string",
                "key_content" => "required|string",
            ],
            [
                "name.required" => "模板名称不能为空",
                "cert_content.required" => "证书内容不能为空",
                "key_content.required" => "私钥内容不能为空",
            ],
        );

        try {
            if (!empty($params["id"])) {
                $template = CertTemplate::find($params["id"]);
                if (!$template) {
                    return $this->fail([400202, "模板不存在"]);
                }
                unset($params["id"]);
                $template->update($params);
                return $this->success($template->fresh());
            }

            unset($params["id"]);
            $template = CertTemplate::create($params);
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

        $template = CertTemplate::find($request->input("id"));
        if (!$template) {
            throw new ApiException("模板不存在");
        }
        if (!$template->delete()) {
            throw new ApiException("删除失败");
        }

        return $this->success(true);
    }
}
