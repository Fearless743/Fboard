<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 兼容入口：语义已改为「重启内嵌内核」，不再重启 fboard-node 进程。
 * 实际逻辑委托给 MachineKernelOpJob。
 */
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
        Log::info("[MachineRestartJob] 转为内核重启", ['machine_id' => $this->machineId]);
        // 同步执行内核 job 逻辑，避免再入队二次延迟
        (new MachineKernelOpJob($this->machineId, 'restart'))->handle();
    }
}
