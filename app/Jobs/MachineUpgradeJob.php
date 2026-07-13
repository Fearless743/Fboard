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

class MachineUpgradeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 30;
    public $uniqueFor = 300;

    public function __construct(
        private readonly int $machineId,
        private readonly string $version = 'latest'
    ) {
        $this->onQueue('node_sync');
    }

    public function uniqueId(): string
    {
        return "machine-upgrade:{$this->machineId}";
    }

    public function handle(): void
    {
        $machine = ServerMachine::find($this->machineId);
        if (!$machine) {
            Log::warning("[MachineUpgradeJob] 机器 {$this->machineId} 不存在，跳过升级");
            return;
        }

        if (!$machine->is_active) {
            Log::info("[MachineUpgradeJob] 机器 {$machine->id} 已禁用，跳过升级");
            return;
        }

        if (!$machine->isOnline()) {
            Log::info("[MachineUpgradeJob] 机器 {$machine->id} 当前离线，跳过升级");
            return;
        }

        NodeSyncService::notifyMachineUpgrade($machine->id, $this->version);

        Log::info("[MachineUpgradeJob] 已通知机器 {$machine->id} 执行 Fboard-Node 升级", [
            'machine_id' => $machine->id,
            'version' => $this->version,
        ]);
    }
}
