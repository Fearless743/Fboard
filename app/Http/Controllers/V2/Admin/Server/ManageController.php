<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Jobs\NodeRestartJob;
use App\Jobs\NodeUpgradeJob;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Support\SudokuKey;
use App\Utils\Helper;
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
        // 运行状态：0 未运行 / 1 无人使用或异常 / 2 运行正常（来自 available_status，非 DB 字段）
        $statusFilter = $request->input("status", "");

        $query = Server::orderBy("sort", "ASC");

        // 类型过滤
        if ($typeFilter) {
            $query->where("type", $typeFilter);
        }

        $query->whereNot("type", "virtual");

        // 搜索过滤
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where("name", "like", "%{$search}%")
                    ->orWhere("host", "like", "%{$search}%")
                    ->orWhere("id", "like", "%{$search}%");
            });
        }

        $enrich = function ($item) {
            $item["groups"] = ServerGroup::whereIn(
                "id",
                $item["group_ids"] ?? [],
            )->get(["name", "id"]);
            $item["parent"] = $item->parent;
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

        // status 依赖缓存心跳，无法直接 SQL 过滤：先取匹配集合再按 available_status 分页
        if ($statusFilter !== "" && $statusFilter !== null && is_numeric($statusFilter)) {
            $status = (int) $statusFilter;
            if (in_array($status, [
                Server::STATUS_OFFLINE,
                Server::STATUS_ONLINE_NO_PUSH,
                Server::STATUS_ONLINE,
            ], true)) {
                $filtered = $query->get()
                    ->filter(fn ($item) => (int) $item->available_status === $status)
                    ->values();

                $total = $filtered->count();
                $slice = $filtered
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
            $deleted = Server::whereIn("id", $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, "批量删除失败"]);
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
            $server->update(
                $request->only([
                    "name",
                    "host",
                    "port",
                    "group_ids",
                    "tags",
                    "show",
                ]),
            );
            return $this->success($server);
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
            $settings = $server->protocol_settings ?? [];
            $settings["virtual_nodes"] = array_values(
                $request->input("virtual_nodes", []),
            );
            $server->protocol_settings = $settings;
            $server->save();
            return $this->success($settings["virtual_nodes"]);
        } catch (\Exception $e) {
            Log::error("保存虚拟节点失败", ["error" => $e->getMessage()]);
            return $this->fail([500, "保存失败"]);
        }
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input("public_name", "ech.example.com");
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException(
                "public_name must be a valid domain (1-253 bytes)",
            );
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = "";
        $contents .= pack("C", $configId); // config_id
        $contents .= pack("n", 0x0020); // kem_id: DHKEM(X25519)
        $contents .= pack("n", 32) . $publicKey; // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack("n", 8); // cipher_suites byte length
        $contents .= pack("nn", 0x0001, 0x0001); // HKDF-SHA256 + AES-128-GCM
        $contents .= pack("nn", 0x0001, 0x0003); // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack("C", 0); // max_name_length
        $contents .= pack("C", strlen($publicName)) . $publicName;
        $contents .= pack("n", 0); // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig =
            pack("n", 0xfe0d) . pack("n", strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack("n", strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload =
            pack("n", 32) .
            $privateKey .
            pack("n", strlen($echConfig)) .
            $echConfig;

        $keyPem =
            "-----BEGIN ECH KEYS-----\n" .
            chunk_split(base64_encode($echKeysPayload), 64, "\n") .
            "-----END ECH KEYS-----";

        $configPem =
            "-----BEGIN ECH CONFIGS-----\n" .
            chunk_split(base64_encode($echConfigList), 64, "\n") .
            "-----END ECH CONFIGS-----";

        return $this->success([
            "key" => $keyPem,
            "config" => $configPem,
        ]);
    }

    /**
     * 生成 Reality x25519 密钥对。
     * 编码必须使用 base64.RawURLEncoding（无填充、URL 安全），
     * 与 Xray / mihomo / Clash.Meta 客户端一致。
     */
    public function generateRealityKey()
    {
        $keypair = sodium_crypto_box_keypair();
        $secretKey = sodium_crypto_box_secretkey($keypair);
        $publicKey = sodium_crypto_box_publickey($keypair);

        return $this->success([
            "public_key" => Helper::base64EncodeUrlSafe($publicKey),
            "private_key" => Helper::base64EncodeUrlSafe($secretKey),
        ]);
    }

    /**
     * 生成 Sudoku Master 密钥对（hex）
     */
    public function generateSudokuKey()
    {
        $pair = SudokuKey::generateMasterKeyPair();
        return $this->success([
            "master_public_key" => $pair["master_public_key"],
            "master_private_key" => $pair["master_private_key"],
        ]);
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
}
