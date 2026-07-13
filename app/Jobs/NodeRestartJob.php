<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NodeRestartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 10;

    public function __construct(
        private readonly int $nodeId
    ) {
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        $server = Server::find($this->nodeId);
        if (!$server) {
            Log::warning("[NodeRestartJob] 节点 {$this->nodeId} 不存在，跳过重启");
            return;
        }

        if ($server->type === 'virtual') {
            Log::info("[NodeRestartJob] 虚拟节点 {$server->id} 不支持重启，跳过");
            return;
        }

        if ($server->machine_id !== null) {
            MachineRestartJob::dispatch((int) $server->machine_id);
            Log::info("[NodeRestartJob] 已将节点 {$server->id} 的重启请求转为机器任务", [
                'machine_id' => $server->machine_id,
            ]);
            return;
        }

        NodeSyncService::notifyNodeRestart($server);

        Log::info("[NodeRestartJob] 已通知独立节点 {$server->id}({$server->name}) 执行重启");
    }
}
