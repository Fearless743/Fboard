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

/**
 * 机器级内核运维：stop / start / reload / restart（仅内嵌 xray，不退出 fboard-node 进程）。
 */
class MachineKernelOpJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 10;
    public $uniqueFor = 300;

    public function __construct(
        private readonly int $machineId,
        private readonly string $action,
    ) {
        $this->onQueue('node_sync');
    }

    public function uniqueId(): string
    {
        $action = strtolower(trim($this->action));
        return "machine-kernel-{$action}:{$this->machineId}";
    }

    public function handle(): void
    {
        $action = strtolower(trim($this->action));
        if (!in_array($action, ['stop', 'start', 'reload', 'restart'], true)) {
            Log::warning("[MachineKernelOpJob] 非法 action={$this->action}，跳过", [
                'machine_id' => $this->machineId,
            ]);
            return;
        }

        $machine = ServerMachine::find($this->machineId);
        if (!$machine) {
            Log::warning("[MachineKernelOpJob] 机器 {$this->machineId} 不存在，跳过 {$action}");
            return;
        }

        if (!$machine->is_active) {
            Log::info("[MachineKernelOpJob] 机器 {$machine->id} 已禁用，跳过 {$action}");
            return;
        }

        if (!$machine->isOnline()) {
            Log::info("[MachineKernelOpJob] 机器 {$machine->id} 当前离线，跳过 {$action}");
            return;
        }

        NodeSyncService::notifyMachineKernel($machine->id, $action);

        Log::info("[MachineKernelOpJob] 已通知机器 {$machine->id} 执行内核 {$action}", [
            'machine_id' => $machine->id,
            'action' => $action,
        ]);
    }
}
