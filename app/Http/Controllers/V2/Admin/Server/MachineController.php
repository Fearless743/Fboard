<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Jobs\MachineKernelOpJob;
use App\Jobs\MachineRestartJob;
use App\Jobs\MachineUpgradeJob;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MachineController extends Controller
{
    /**
     * 获取机器列表（附带关联节点数，分页）
     *
     * 可选参数：
     *   - search: 在 name / notes 上做大小写不敏感的模糊匹配
     *   - status: all / online / offline / inactive / high_load（仅做 SQL 粗筛，
     *     在线/高负载的精确判断由前端二次过滤兜底，见 index.tsx）
     *
     * 响应额外字段 summary（全局概览，不受 search 影响）：
     *   total / online / offline / high_load / nodes
     */
    public function fetch(Request $request)
    {
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $query = ServerMachine::withCount('servers');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->pinyinSearch($search, ['name', 'notes']);
        }

        // 状态粗筛：inactive 可直接用 DB 字段；在线/离线/高负载只约束
        // 可用子集，精确判断交给前端二次过滤
        $status = (string) $request->input('status', 'all');
        if ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'online') {
            $query->where('is_active', true)
                  ->where(function ($q) {
                      $q->whereNotNull('last_seen_at')
                        ->where('last_seen_at', '>=', time() - Server::CHECK_INTERVAL);
                  });
        } elseif ($status === 'offline') {
            $query->where('is_active', true)
                  ->where(function ($q) {
                      $q->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', time() - Server::CHECK_INTERVAL);
                  });
        } elseif ($status === 'high_load') {
            $query->where('is_active', true)
                  ->whereNotNull('last_seen_at')
                  ->where('last_seen_at', '>=', time() - Server::CHECK_INTERVAL);
        }

        $machines = $query->orderBy('id')->paginate(perPage: $pageSize, page: $current);

        $machines->getCollection()->transform(function (ServerMachine $machine) {
            return [
                'id' => $machine->id,
                'name' => $machine->name,
                'notes' => $machine->notes,
                'is_active' => $machine->is_active,
                'is_online' => $machine->isOnline(),
                'last_seen_at' => $machine->last_seen_at,
                'load_status' => $machine->load_status,
                'servers_count' => $machine->servers_count,
                'created_at' => $machine->created_at,
                'updated_at' => $machine->updated_at,
            ];
        });

        return response()->json([
            'total' => $machines->total(),
            'current_page' => $machines->currentPage(),
            'per_page' => $machines->perPage(),
            'last_page' => $machines->lastPage(),
            'data' => $machines->items(),
            // 顶部概览：全局统计，与当前搜索/分页无关
            'summary' => $this->buildMachineSummary(),
        ]);
    }

    /**
     * 服务器管理页顶部概览统计。
     * 高负载阈值与前端 LoadMeter 一致：CPU / 内存 / 磁盘任一 ≥ 70%。
     */
    private function buildMachineSummary(): array
    {
        $all = ServerMachine::query()
            ->select(['id', 'is_active', 'last_seen_at', 'load_status'])
            ->get();

        $total = $all->count();
        $online = 0;
        $highLoad = 0;

        foreach ($all as $machine) {
            $isOnline = $machine->isOnline();
            if ($isOnline) {
                $online++;
            }

            if ($this->isHighLoad($machine->load_status)) {
                $highLoad++;
            }
        }

        $nodes = Server::query()
            ->whereNotNull('machine_id')
            ->where('machine_id', '>', 0)
            ->whereNot('type', 'virtual')
            ->count();

        return [
            'total' => $total,
            'online' => $online,
            'offline' => max(0, $total - $online),
            'high_load' => $highLoad,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $load
     */
    private function isHighLoad(?array $load): bool
    {
        if (empty($load) || !is_array($load)) {
            return false;
        }

        $cpu = isset($load['cpu']) ? (float) $load['cpu'] : null;
        $memUsed = (float) data_get($load, 'mem.used', 0);
        $memTotal = (float) data_get($load, 'mem.total', 0);
        $diskUsed = (float) data_get($load, 'disk.used', 0);
        $diskTotal = (float) data_get($load, 'disk.total', 0);

        $mem = $memTotal > 0 ? ($memUsed / $memTotal) * 100 : null;
        $disk = $diskTotal > 0 ? ($diskUsed / $diskTotal) * 100 : null;

        foreach ([$cpu, $mem, $disk] as $value) {
            if ($value !== null && $value >= 70) {
                return true;
            }
        }

        return false;
    }

    /**
     * 创建 / 更新机器
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($params['id'])) {
            $machine = ServerMachine::find($params['id']);
            $update = ['name' => $params['name']];
            if (array_key_exists('notes', $params)) {
                $update['notes'] = $params['notes'];
            }
            if (array_key_exists('is_active', $params)) {
                $update['is_active'] = $params['is_active'];
            }
            $machine->update($update);
            return $this->success(true);
        }

        $machine = ServerMachine::create([
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'token' => ServerMachine::generateToken(),
        ]);

        return $this->success([
            'id' => $machine->id,
            'token' => $machine->token,
            'install_command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 重置机器 Token
     */
    public function resetToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $token = ServerMachine::generateToken();
        $machine->update(['token' => $token]);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取机器 Token（仅展示一次，用于首次配置）
     */
    public function getToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success(['token' => $machine->token]);
    }

    /**
     * 获取机器模式一键安装命令
     */
    public function installCommand(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success([
            'command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 删除机器（自动解除关联节点）
     */
    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $machineId = $machine->id;

        // Detach nodes first (sets machine_id = null), then delete and notify
        Server::where('machine_id', $machineId)->update(['machine_id' => null]);
        $machine->delete();

        // Notify with empty node list so WS process cleans up registry
        NodeSyncService::notifyMachineNodesChanged($machineId);

        return $this->success(true);
    }

    /**
     * 获取机器下的节点列表
     */
    public function nodes(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $nodes = Server::where('machine_id', $params['machine_id'])
            ->orderBy('sort')
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort']);

        return $this->success($nodes);
    }

    /**
     * 获取机器负载历史
     */
    public function history(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1440',
            'range_hours' => 'nullable|integer|min:1|max:24',
        ]);

        $query = ServerMachineLoadHistory::query()
            ->where('machine_id', $params['machine_id']);

        if (!empty($params['range_hours'])) {
            $query->where('recorded_at', '>=', now()->subHours((int) $params['range_hours'])->timestamp);
        }

        $limit = (int) ($params['limit'] ?? 60);

        $history = $query
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get([
                'cpu',
                'mem_total',
                'mem_used',
                'disk_total',
                'disk_used',
                'net_in_speed',
                'net_out_speed',
                'recorded_at',
            ])
            ->reverse()
            ->values();

        return $this->success($history);
    }

    /**
     * 升级指定机器上的 Fboard-Node 服务。
     */
    public function upgrade(Request $request)
    {
        $machine = $this->getOperableMachine($request);
        if (!($machine instanceof ServerMachine)) {
            return $machine;
        }

        MachineUpgradeJob::dispatch($machine->id);

        Log::info('机器 Fboard-Node 升级任务已提交', [
            'machine_id' => $machine->id,
            'machine_name' => $machine->name,
        ]);

        return $this->success([
            'submitted' => true,
            'machine_id' => $machine->id,
        ]);
    }

    /**
     * 重启指定机器上所有节点的内嵌 xray 内核（进程与 WS 保持存活）。
     */
    public function restart(Request $request)
    {
        return $this->dispatchKernelOp($request, 'restart');
    }

    /**
     * 停止指定机器上所有节点的内嵌 xray 内核。
     */
    public function stop(Request $request)
    {
        return $this->dispatchKernelOp($request, 'stop');
    }

    /**
     * 启动指定机器上所有节点的内嵌 xray 内核。
     */
    public function start(Request $request)
    {
        return $this->dispatchKernelOp($request, 'start');
    }

    /**
     * 重载指定机器上所有节点的内嵌 xray 内核配置。
     */
    public function reload(Request $request)
    {
        return $this->dispatchKernelOp($request, 'reload');
    }

    /**
     * 校验机器可运维后投递内核操作任务。
     */
    private function dispatchKernelOp(Request $request, string $action)
    {
        $machine = $this->getOperableMachine($request);
        if (!($machine instanceof ServerMachine)) {
            return $machine;
        }

        MachineKernelOpJob::dispatch($machine->id, $action);

        Log::info("机器内核 {$action} 任务已提交", [
            'machine_id' => $machine->id,
            'machine_name' => $machine->name,
            'action' => $action,
        ]);

        return $this->success([
            'submitted' => true,
            'machine_id' => $machine->id,
            'action' => $action,
        ]);
    }

    /**
     * 一键升级所有在线机器上的 Fboard-Node 服务。
     */
    public function batchUpgrade()
    {
        $stats = [
            'submitted' => 0,
            'skipped' => [
                'inactive' => 0,
                'offline' => 0,
            ],
        ];

        foreach (ServerMachine::orderBy('id')->get() as $machine) {
            if (!$machine->is_active) {
                $stats['skipped']['inactive']++;
                continue;
            }

            if (!$machine->isOnline()) {
                $stats['skipped']['offline']++;
                continue;
            }

            MachineUpgradeJob::dispatch($machine->id);
            $stats['submitted']++;
        }

        Log::info('批量机器 Fboard-Node 升级任务已提交', $stats);

        return $this->success($stats);
    }


    /**
     * 拉取机器运行日志（通过 WS 请求节点内存中的最近日志）。
     */
    public function logs(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1000',
            'refresh' => 'nullable|boolean',
        ]);

        $machine = ServerMachine::find($params['id']);
        if (!$machine) {
            return $this->fail([400202, '服务器不存在']);
        }

        $limit = (int) ($params['limit'] ?? 500);
        $refresh = array_key_exists('refresh', $params)
            ? (bool) $params['refresh']
            : true;

        $cached = Cache::get("machine_logs:{$machine->id}");
        // HTTP process cannot inspect Workerman NodeRegistry; last_seen is the signal.
        $online = $machine->isOnline();

        if (!$online) {
            return $this->success([
                'online' => false,
                'lines' => is_array($cached['lines'] ?? null) ? $cached['lines'] : [],
                'updated_at' => $cached['updated_at'] ?? null,
                'stale' => true,
                'message' => '服务器当前离线，显示最近一次缓存日志（如有）',
            ]);
        }

        if ($refresh || !is_array($cached) || empty($cached['lines'])) {
            $reqId = bin2hex(random_bytes(8));
            NodeSyncService::notifyMachineLogs($machine->id, $limit, $reqId);

            $deadline = microtime(true) + 3.0;
            $payload = null;
            while (microtime(true) < $deadline) {
                usleep(100_000);
                $payload = Cache::get("machine_logs_req:{$machine->id}:{$reqId}");
                if (is_array($payload)) {
                    break;
                }
                // Also accept any newer machine_logs written without matching req
                $latest = Cache::get("machine_logs:{$machine->id}");
                if (is_array($latest) && ($latest['req_id'] ?? null) === $reqId) {
                    $payload = $latest;
                    break;
                }
            }

            if (!is_array($payload)) {
                $fallback = Cache::get("machine_logs:{$machine->id}");
                return $this->success([
                    'online' => true,
                    'lines' => is_array($fallback['lines'] ?? null) ? $fallback['lines'] : [],
                    'updated_at' => $fallback['updated_at'] ?? null,
                    'stale' => true,
                    'message' => '等待节点日志超时，显示缓存（如有）。请确认 WebSocket 服务与节点在线。',
                ]);
            }

            $lines = $payload['lines'] ?? [];
            if (!is_array($lines)) {
                $lines = [];
            }
            if (count($lines) > $limit) {
                $lines = array_slice($lines, -$limit);
            }

            return $this->success([
                'online' => true,
                'lines' => array_values($lines),
                'updated_at' => $payload['updated_at'] ?? time(),
                'stale' => false,
                'req_id' => $reqId,
            ]);
        }

        $lines = $cached['lines'] ?? [];
        if (!is_array($lines)) {
            $lines = [];
        }
        if (count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }

        return $this->success([
            'online' => true,
            'lines' => array_values($lines),
            'updated_at' => $cached['updated_at'] ?? null,
            'stale' => true,
        ]);
    }

    /**
     * 查找可接收机器级运维命令的机器。
     * 机器级操作不依赖节点数量，机器本身就是升级/内核运维目标。
     */
    private function getOperableMachine(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
        ]);

        $machine = ServerMachine::find($params['id']);
        if (!$machine) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (!$machine->is_active) {
            return $this->fail([409001, '服务器已禁用']);
        }

        if (!$machine->isOnline()) {
            return $this->fail([409002, '服务器当前离线，无法执行运维操作']);
        }

        return $machine;
    }

    private function buildInstallCommand(Request $request, ServerMachine $machine): string
    {
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');
        $installerUrl = admin_setting('node_install_script_url') ?: 'https://raw.githubusercontent.com/Fearless743/Fboard-Node/master/install.sh';

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --token %s --machine-id %d',
            $installerUrl,
            escapeshellarg($panelUrl),
            escapeshellarg($machine->token),
            $machine->id
        );
    }
}
