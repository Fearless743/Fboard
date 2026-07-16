<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
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
     * 可选搜索参数：
     *   - search: 在 name / notes 上做大小写不敏感的模糊匹配
     */
    public function fetch(Request $request)
    {
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $query = ServerMachine::withCount('servers');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('notes', 'like', $like);
            });
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

        return $this->paginate($machines);
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
     * 重启指定机器上的 Fboard-Node 服务。
     */
    public function restart(Request $request)
    {
        $machine = $this->getOperableMachine($request);
        if (!($machine instanceof ServerMachine)) {
            return $machine;
        }

        MachineRestartJob::dispatch($machine->id);

        Log::info('机器 Fboard-Node 重启任务已提交', [
            'machine_id' => $machine->id,
            'machine_name' => $machine->name,
        ]);

        return $this->success([
            'submitted' => true,
            'machine_id' => $machine->id,
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
     * 机器级操作不依赖节点数量，机器本身就是升级/重启目标。
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
