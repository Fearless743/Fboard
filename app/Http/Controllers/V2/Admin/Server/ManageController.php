<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Jobs\NodeRestartJob;
use App\Jobs\NodeUpgradeJob;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $current = max(1, (int) $request->input("current", 1));
        $pageSize = max(1, min(200, (int) $request->input("pageSize", 20)));
        $search = $request->input("search", "");
        $typeFilter = $request->input("type", "");
        $machineId = $request->input("machine_id", "");
        // 运行状态：0 未运行 / 1 无人使用或异常 / 2 运行正常（来自 available_status，非 DB 字段）
        $statusFilter = $request->input("status", "");
        // 排序：sort_by 仅支持 online（在线人数）；order 支持 asc/desc
        $sortBy = $request->input("sort_by", "");
        $sortOrder = strtolower($request->input("order", "desc")) === "asc" ? "asc" : "desc";
        $onlineSort = $sortBy === "online";

        $query = Server::orderBy("sort", "ASC");

        // 类型过滤
        if ($typeFilter) {
            $query->where("type", $typeFilter);
        }

        $query->whereNot("type", "virtual");

        // 机器过滤
        if ($machineId !== "" && $machineId !== null && is_numeric($machineId)) {
            $query->where("machine_id", (int) $machineId);
        }

        // 搜索过滤：name 支持拼音（含 pinyin_index 匹配），host/id 仅原文匹配
        if ($search) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $like = "%{$escaped}%";
            $query->where(function ($q) use ($like, $search) {
                // name 支持拼音搜索（原文 + pinyin_index）
                $q->pinyinSearch($search, ['name']);
                // host / id 仅原文 LIKE
                $q->orWhere('host', 'like', $like)
                  ->orWhere('id', 'like', $like);
            });
        }

        // 当月流量汇总（server_id => [upload, download, total]）
        // 虚拟节点流量归入父节点，此处仅查询非虚拟节点
        $trafficMap = [];
        $baseIds = $query->pluck('id');
        if ($baseIds->isNotEmpty()) {
            $monthStart = strtotime(date('Y-m-01'));
            $monthEnd   = strtotime('+1 month', $monthStart);
            $rawStats   = StatServer::selectRaw('server_id, SUM(u) as upload, SUM(d) as download')
                ->where('record_at', '>=', $monthStart)
                ->where('record_at', '<', $monthEnd)
                ->whereIn('server_id', $baseIds)
                ->groupBy('server_id')
                ->get();
            foreach ($rawStats as $s) {
                $trafficMap[(int) $s->server_id] = [
                    'upload'   => (int) $s->upload,
                    'download' => (int) $s->download,
                    'total'    => (int) $s->upload + (int) $s->download,
                ];
            }
        }

        $enrich = function ($item) use ($trafficMap) {
            $item["groups"] = ServerGroup::whereIn(
                "id",
                $item["group_ids"] ?? [],
            )->get(["name", "id"]);
            $item["parent"] = $item->parent;
            // 当月流量（虚拟节点聚合到父节点已在 getMonthTraffic 中处理，
            // 此处直接取本节点的当月统计）
            $serverId = $item->parent_id ?: $item->id;
            $item["month_traffic"] = $trafficMap[$serverId] ?? [
                'upload'   => 0,
                'download' => 0,
                'total'    => 0,
            ];
            // online / available_status 等为 Attribute，需 append 才会进入 JSON
            $item->append([
                'version',
                'online',
                'is_online',
                'last_check_at',
                'last_push_at',
                'available_status',
            ]);
            // 管理端列表用 status 展示节点状态（0 离线 / 1 在线无推送 / 2 在线）
            $item->setAttribute('status', $item->available_status);
            return $item;
        };

        // 以下两类值依赖缓存访问器（status 见 available_status / online），无法用 SQL 排序，
        // 需先取匹配集合再在内存中过滤/排序后分页。
        $needInMemory = $onlineSort || ($statusFilter !== "" && $statusFilter !== null && is_numeric($statusFilter));
        if ($needInMemory) {
            $collection = $query->get();

            // 按运行状态过滤
            $status = (int) $statusFilter;
            if ($statusFilter !== "" && $statusFilter !== null && is_numeric($statusFilter)
                && in_array($status, [
                    Server::STATUS_OFFLINE,
                    Server::STATUS_ONLINE_NO_PUSH,
                    Server::STATUS_ONLINE,
                ], true)) {
                $collection = $collection->filter(
                    fn ($item) => (int) $item->available_status === $status,
                );
            }

            // 按在线人数排序（在线人数依赖缓存访问器，非 DB 列，只能内存排序）
            if ($onlineSort) {
                $collection = $collection->sortBy("online", SORT_NUMERIC, $sortOrder === "asc" ? false : true);
            }

            $collection = $collection->values();
            $total = $collection->count();
            $slice = $collection
                ->slice(($current - 1) * $pageSize, $pageSize)
                ->values()
                ->map($enrich);

            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $slice,
                $total,
                $pageSize,
                $current,
                ['path' => $request->url(), 'query' => $request->query()],
            );

            return $this->paginate($paginator);
        }

        $servers = $query->paginate($pageSize, ["*"], "page", $current);
        $servers->getCollection()->transform($enrich);

        return $this->paginate($servers);
    }

    /**
     * 获取用于排序的精简节点列表（仅 id/name/sort/type 四字段，含虚拟节点）
     */
    public function getSortNodes()
    {
        $nodes = Server::orderBy("sort", "ASC")->get([
            "id",
            "parent_id",
            "name",
            "sort",
            "type",
        ]);
        return $this->success($nodes);
    }

    public function sort(Request $request)
    {
        ini_set("post_max_size", "1m");

        // 兼容两种请求体：
        // 1) 新前端: { ids: [3, 1, 2] }  （与 plan/notice/payment 一致，按数组下标写 sort）
        // 2) 旧前端: [{ id: 1, order: 1 }, ...]
        if ($request->has('ids')) {
            $params = $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'numeric',
            ]);
            $items = collect($params['ids'])->values()->map(function ($id, $index) {
                return ['id' => (int) $id, 'order' => $index + 1];
            });
        } else {
            $params = $request->validate([
                '*.id' => 'required|numeric',
                '*.order' => 'required|numeric',
            ]);
            $items = collect($params)->filter(function ($item) {
                return isset($item['id'], $item['order']);
            })->values();
        }

        if ($items->isEmpty()) {
            return $this->fail([422, '参数有误']);
        }

        HookManager::call('admin.server.sort.before', [
            'params' => $items->all(),
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            $items->each(function ($item) {
                Server::where('id', $item['id'])->update([
                    'sort' => $item['order'],
                ]);
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, "保存失败"]);
        }

        HookManager::call('admin.server.sort.after', [
            'params' => $items->all(),
            'request' => $request,
        ]);

        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input("id")) {
            $server = Server::find($request->input("id"));
            if (!$server) {
                return $this->fail([400202, "服务器不存在"]);
            }

            HookManager::call('admin.server.save.before', [
                'server' => $server,
                'params' => $params,
                'request' => $request,
            ]);

            try {
                $server->update($params);

                HookManager::call('admin.server.save.after', [
                    'server' => $server,
                    'params' => $params,
                    'request' => $request,
                ]);

                return $this->success(true);
            } catch (\InvalidArgumentException $e) {
                return $this->fail([400, $e->getMessage()]);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, "保存失败"]);
            }
        }

        HookManager::call('admin.server.save.before', [
            'server' => null,
            'params' => $params,
            'request' => $request,
        ]);

        try {
            $server = Server::create($params);

            HookManager::call('admin.server.save.after', [
                'server' => $server,
                'params' => $params,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "创建失败"]);
        }
    }

    public function update(ServerSave $request)
    {
        $params = $request->validated();

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, "服务器不存在"]);
        }

        HookManager::call('admin.server.update.before', [
            'server' => $server,
            'params' => $params,
            'request' => $request,
        ]);

        try {
            $server->update($params);

            HookManager::call('admin.server.update.after', [
                'server' => $server,
                'params' => $params,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "保存失败"]);
        }
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            "id" => "required|integer",
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, "服务器不存在"]);
        }

        HookManager::call('admin.server.drop.before', [
            'server' => $server,
            'request' => $request,
        ]);

        if ($server->delete() === false) {
            return $this->fail([500, "删除失败"]);
        }

        HookManager::call('admin.server.drop.after', [
            'server' => $server,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            "ids" => "required|array",
            "ids.*" => "integer",
        ]);

        $ids = $request->input("ids");
        if (empty($ids)) {
            return $this->fail([400, "请选择要删除的节点"]);
        }

        HookManager::call('admin.server.batch_delete.before', [
            'ids' => $ids,
            'request' => $request,
        ]);

        try {
            // 必须逐模型 delete：mass delete 不触发 deleting 事件，
            // 父节点的虚拟子节点级联删除依赖 Server::booted() 中的 deleting 钩子。
            $servers = Server::whereIn("id", $ids)->get();
            foreach ($servers as $server) {
                if (!$server->delete()) {
                    return $this->fail([500, "批量删除失败"]);
                }
            }

            HookManager::call('admin.server.batch_delete.after', [
                'ids' => $ids,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "批量删除失败"]);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            "id" => "required|integer",
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, "服务器不存在"]);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();

            Log::info(
                "Server {$server->id} ({$server->name}) traffic reset by admin",
            );
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "重置失败"]);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            "ids" => "required|array",
            "ids.*" => "integer",
        ]);

        $ids = $request->input("ids");
        if (empty($ids)) {
            return $this->fail([400, "请选择要重置的节点"]);
        }

        try {
            Server::whereIn("id", $ids)->update([
                "u" => 0,
                "d" => 0,
            ]);

            Log::info(
                "Servers " . implode(",", $ids) . " traffic reset by admin",
            );
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "批量重置失败"]);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            "ids" => "required|array",
            "ids.*" => "integer",
            "show" => "nullable|integer|in:0,1",
            "enabled" => "nullable|boolean",
            "machine_id" => "nullable|integer",
        ]);

        $ids = $params["ids"];
        if (empty($ids)) {
            return $this->fail([400, "请选择要更新的节点"]);
        }

        $update = [];
        if (array_key_exists("show", $params) && $params["show"] !== null) {
            $update["show"] = (int) $params["show"];
        }
        if (
            array_key_exists("enabled", $params) &&
            $params["enabled"] !== null
        ) {
            $update["enabled"] = (bool) $params["enabled"];
        }
        if (array_key_exists("machine_id", $params)) {
            $update["machine_id"] = $params["machine_id"] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, "没有可更新的字段"]);
        }

        HookManager::call('admin.server.batch_update.before', [
            'ids' => $ids,
            'update' => $update,
            'request' => $request,
        ]);

        try {
            $servers = Server::whereIn("id", $ids)->get();
            DB::transaction(function () use ($servers, $update) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $server->update($update);
                }
            });

            HookManager::call('admin.server.batch_update.after', [
                'ids' => $ids,
                'update' => $update,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "批量更新失败"]);
        }
    }

    /**
     * 批量替换节点字段值：对指定字段做「搜索值 → 替换值」的字符串替换。
     * 标量字段直接替换；JSON 字段对序列化文本替换（数组元素、嵌套值一并命中）。
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchReplace(Request $request)
    {
        $params = $request->validate([
            "field" => "required|string",
            "search" => "required|string",
            "replace" => "present|string",
        ]);

        $field = $params["field"];
        $search = $params["search"];
        $replace = $params["replace"];

        // 字段白名单，防止任意列被写入；按存储形态区分替换策略
        // 仅保留 v2_server 真实存在的列（listen_address / dynamic_rate / ech 为前端展示字段或存于 protocol_settings）
        $allowedFields = [
            // 标量字段：直接字符串替换
            "name" => "scalar",
            "host" => "scalar",
            "port" => "scalar",
            "code" => "scalar",
            // JSON 字段：对序列化后的 JSON 文本替换，覆盖数组元素与嵌套值
            "group_ids" => "json",
            "route_ids" => "json",
            "tags" => "json",
            "protocol_settings" => "json",
            "custom_outbounds" => "json",
            "custom_routes" => "json",
            "cert_config" => "json",
            "rate_time_ranges" => "json",
        ];

        if (!isset($allowedFields[$field])) {
            return $this->fail([400, "该字段不支持批量替换"]);
        }

        $kind = $allowedFields[$field];
        $changed = 0;

        HookManager::call('admin.server.batch_replace.before', [
            'field' => $field,
            'search' => $search,
            'replace' => $replace,
            'request' => $request,
        ]);

        try {
            DB::transaction(function () use (
                $field,
                $kind,
                $search,
                $replace,
                &$changed
            ) {
                $servers = Server::query()->get();
                /** @var Server $server */
                foreach ($servers as $server) {
                    if ($kind === "json") {
                        $raw = $server->getRawOriginal($field);
                        if ($raw === null || $raw === "") {
                            continue;
                        }
                        $newRaw = str_replace($search, $replace, (string) $raw);
                        if ($newRaw === (string) $raw) {
                            continue;
                        }
                        $server->{$field} = json_decode($newRaw, true);
                    } else {
                        $old = $server->{$field};
                        if ($old === null) {
                            continue;
                        }
                        $new = str_replace($search, $replace, (string) $old);
                        if ($new === (string) $old) {
                            continue;
                        }
                        $server->{$field} = $new;
                    }

                    $server->save();
                    $changed++;
                }
            });

            HookManager::call('admin.server.batch_replace.after', [
                'field' => $field,
                'search' => $search,
                'replace' => $replace,
                'changed' => $changed,
                'request' => $request,
            ]);

            Log::info("Server batch replace", [
                "field" => $field,
                "changed" => $changed,
            ]);

            return $this->success(["changed" => $changed]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, "批量替换失败"]);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input("id"));
        if (!$server) {
            return $this->fail([400202, "服务器不存在"]);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    /**
     * 创建子节点（继承父节点配置）
     */
    public function createChildNode(Request $request)
    {
        $request->validate([
            "parent_id" => "required|integer|exists:v2_server,id",
            "name" => "required|string|max:255",
            "host" => "required|string",
            "port" => "required|integer",
            "group_ids" => "nullable|array",
            "tags" => "nullable|array",
            "show" => "boolean",
        ]);

        try {
            $server = Server::createVirtual($request->all());
            return $this->success($server);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error("创建子节点失败", ["error" => $e->getMessage()]);
            return $this->fail([500, "创建失败"]);
        }
    }

    /**
     * 更新子节点
     */
    public function updateChildNode(Request $request)
    {
        $request->validate([
            "id" => "required|integer|exists:v2_server,id",
            "name" => "required|string|max:255",
            "host" => "required|string",
            "port" => "required|integer",
            "group_ids" => "nullable|array",
            "tags" => "nullable|array",
            "show" => "boolean",
        ]);

        try {
            $server = Server::find($request->input("id"));
            if (!$server || $server->type !== "virtual") {
                return $this->fail([400, "子节点不存在"]);
            }

            $payload = $request->only([
                "name",
                "host",
                "port",
                "group_ids",
                "tags",
                "show",
            ]);

            // 子节点权限组不能超出父节点（连接由父节点进程承载）
            if (array_key_exists("group_ids", $payload)) {
                $parent = $server->parent;
                if (!$parent) {
                    return $this->fail([400, "父节点不存在"]);
                }
                $payload["group_ids"] = Server::assertGroupIdsWithinParent(
                    $payload["group_ids"],
                    $parent->group_ids,
                );
            }

            $server->update($payload);
            return $this->success($server);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error("更新子节点失败", ["error" => $e->getMessage()]);
            return $this->fail([500, "更新失败"]);
        }
    }

    /**
     * 获取虚拟子节点列表（返回完整字段供编辑）
     */
    public function getChildren(int $id)
    {
        $children = Server::where("parent_id", $id)
            ->where("type", "virtual")
            ->get([
                "id",
                "name",
                "host",
                "port",
                "server_port",
                "group_ids",
                "tags",
                "show",
                "sort",
            ]);
        return $this->success($children);
    }

    /**
     * 获取虚拟节点列表
     */
    public function getVirtualNodes(int $id)
    {
        $server = Server::find($id);
        if (!$server) {
            return $this->fail([404, "节点不存在"]);
        }
        return $this->success($server->getVirtualNodes());
    }

    /**
     * 保存虚拟节点列表（旧方案，保留兼容）
     */
    public function saveVirtualNodes(int $id, Request $request)
    {
        $server = Server::find($id);
        if (!$server) {
            return $this->fail([404, "节点不存在"]);
        }

        $request->validate([
            "virtual_nodes" => "array",
            "virtual_nodes.*.host" => "required|string",
            "virtual_nodes.*.port" => "required|integer",
            "virtual_nodes.*.group_ids" => "nullable|array",
            "virtual_nodes.*.tags" => "nullable|array",
        ]);

        try {
            $nodes = array_values($request->input("virtual_nodes", []));
            // 旧 JSON 方案同样约束：虚拟入口权限组 ⊆ 父节点
            foreach ($nodes as $i => $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (array_key_exists("group_ids", $node)) {
                    $nodes[$i]["group_ids"] = Server::assertGroupIdsWithinParent(
                        $node["group_ids"] ?? [],
                        $server->group_ids,
                    );
                }
            }

            $settings = $server->protocol_settings ?? [];
            $settings["virtual_nodes"] = $nodes;
            $server->protocol_settings = $settings;
            $server->save();
            return $this->success($settings["virtual_nodes"]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error("保存虚拟节点失败", ["error" => $e->getMessage()]);
            return $this->fail([500, "保存失败"]);
        }
    }

    /**
     * 兼容旧入口：重启独立节点内核；机器节点会转为机器级内核重启。
     */
    public function restart(Request $request)
    {
        $request->validate([
            "id" => "required|integer",
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, "服务器不存在"]);
        }

        if ($server->type === "virtual") {
            return $this->fail([400, "虚拟节点不支持重启"]);
        }

        NodeRestartJob::dispatch($server->id);

        Log::info("节点内核重启任务已提交", [
            "node_id" => $server->id,
            "node_name" => $server->name,
        ]);
        return $this->success(true);
    }

    /**
     * 兼容旧入口：升级独立节点，机器节点会转为机器级升级。
     */
    public function upgrade(Request $request)
    {
        $request->validate([
            "id" => "required|integer",
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, "服务器不存在"]);
        }

        if ($server->type === "virtual") {
            return $this->fail([400, "虚拟节点不支持升级"]);
        }

        NodeUpgradeJob::dispatch($request->id);

        Log::info("Node upgrade dispatched", ["node_id" => $request->id, "node_name" => $server->name]);
        return $this->success(true);
    }

    /**
     * 兼容旧入口：按机器去重升级所有在线机器。
     */
    public function batchUpgrade()
    {
        NodeUpgradeJob::dispatch();

        Log::info("Batch machine upgrade dispatched (legacy node route)");
        return $this->success(true);
    }

    /**
     * 获取各服务器当月的流量汇总（供前端列表字段回显）。
     *
     * 聚合逻辑：
     * - 非虚拟节点：各自当月 u+d 总和
     * - 虚拟节点：流量归入其父节点（连接由父节点承载，统计亦归属父节点）
     *
     * 返回结构：
     * [
     *   'list' => [
     *     ['server_id' => int, 'upload' => int, 'download' => int, 'total' => int],
     *     ...
     *   ],
     *   'month' => string 格式 "Y-m"
     * ]
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMonthTraffic(Request $request)
    {
        $month = $request->input('month', date('Y-m'));

        // 校验月份格式
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->fail([400, "月份格式不正确，应为 Y-m"]);
        }

        $monthStart = strtotime("$month-01");
        $monthEnd = strtotime('+1 month', $monthStart);

        // 统计当月各服务器的流量（按 server_id 聚合）
        // 虚拟节点的流量归入父节点：LEFT JOIN 父节点后按父节点 ID 分组
        $stats = StatServer::selectRaw('server_id, SUM(u) as upload, SUM(d) as download')
            ->where('record_at', '>=', $monthStart)
            ->where('record_at', '<', $monthEnd)
            ->groupBy('server_id')
            ->get();

        // 构建 server_id → 流量映射
        $trafficMap = [];
        foreach ($stats as $s) {
            $trafficMap[(int) $s->server_id] = [
                'upload'   => (int) $s->upload,
                'download' => (int) $s->download,
                'total'    => (int) $s->upload + (int) $s->download,
            ];
        }

        // 非虚拟节点直接展示；虚拟节点流量合并到父节点
        $servers = Server::whereNot('type', 'virtual')->get(['id', 'parent_id']);

        // 以父节点 ID 为 key 的流量累计（虚拟节点流量归父）
        $aggregated = [];
        foreach ($servers as $server) {
            $aggregateId = $server->parent_id ?: $server->id;
            if (!isset($aggregated[$aggregateId])) {
                $aggregated[$aggregateId] = [
                    'server_id' => $aggregateId,
                    'upload'    => 0,
                    'download'  => 0,
                ];
            }
            if (isset($trafficMap[$server->id])) {
                $aggregated[$aggregateId]['upload']    += $trafficMap[$server->id]['upload'];
                $aggregated[$aggregateId]['download']  += $trafficMap[$server->id]['download'];
            }
        }

        // 转换为列表格式
        $list = array_values(array_map(function ($item) {
            $item['total'] = $item['upload'] + $item['download'];
            return $item;
        }, $aggregated));

        return $this->success([
            'list'  => $list,
            'month' => $month,
        ]);
    }
}
