<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NodeUpgradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 30;

    /**
     * @param int|null $nodeId 要升级的节点ID；null 表示按机器批量升级
     */
    public function __construct(
        private readonly ?int $nodeId = null
    ) {
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        if ($this->nodeId !== null) {
            $this->upgradeNode($this->nodeId);
            return;
        }

        // 兼容旧的一键升级入口，机器本身就是升级目标，不依赖节点数量。
        $machines = ServerMachine::where('is_active', true)
            ->orderBy('id')
            ->get(['id']);

        if ($machines->isEmpty()) {
            Log::info('[NodeUpgradeJob] 没有找到可升级的机器');
            return;
        }

        foreach ($machines as $machine) {
            MachineUpgradeJob::dispatch((int) $machine->id);
        }
    }

    private function upgradeNode(int $nodeId): void
    {
        $server = Server::find($nodeId);
        if (!$server) {
            Log::warning("[NodeUpgradeJob] 节点 {$nodeId} 不存在，跳过升级");
            return;
        }

        if ($server->type === 'virtual') {
            Log::info("[NodeUpgradeJob] 虚拟节点 {$nodeId} 不支持升级，跳过");
            return;
        }

        if ($server->machine_id !== null) {
            MachineUpgradeJob::dispatch((int) $server->machine_id);
            Log::info("[NodeUpgradeJob] 已将节点 {$nodeId} 的升级请求转为机器任务", [
                'machine_id' => $server->machine_id,
            ]);
            return;
        }

        NodeSyncService::notifyNodeUpgrade($nodeId);

        Log::info("[NodeUpgradeJob] 已通知独立节点 {$nodeId}({$server->name}) 执行升级");
    }
}
