<?php

namespace App\Jobs;

use App\Models\ServerMachine;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MachineRestartJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 10;
    public $uniqueFor = 300;

    public function __construct(
        private readonly int $machineId
    ) {
        $this->onQueue('node_sync');
    }

    public function uniqueId(): string
    {
        return "machine-restart:{$this->machineId}";
    }

    public function handle(): void
    {
        $machine = ServerMachine::find($this->machineId);
        if (!$machine) {
            Log::warning("[MachineRestartJob] 机器 {$this->machineId} 不存在，跳过重启");
            return;
        }

        if (!$machine->is_active) {
            Log::info("[MachineRestartJob] 机器 {$machine->id} 已禁用，跳过重启");
            return;
        }

        if (!$machine->isOnline()) {
            Log::info("[MachineRestartJob] 机器 {$machine->id} 当前离线，跳过重启");
            return;
        }

        NodeSyncService::notifyMachineRestart($machine->id);

        Log::info("[MachineRestartJob] 已通知机器 {$machine->id} 执行 Fboard-Node 服务重启", [
            'machine_id' => $machine->id,
        ]);
    }
}
