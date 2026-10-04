<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserPlan;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 10;

    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly ?int $oldGroupId = null
    ) {
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($this->action === 'updated' || $this->action === 'created') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
            if ($user) {
                NodeSyncService::notifyUserChanged($user);
            }
        } elseif ($this->action === 'deleted') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            } else {
                // 主表已无 group_id：从该用户所有实例组移除。
                UserPlan::where('user_id', $this->userId)
                    ->distinct()->pluck('group_id')
                    ->each(fn ($gid) => NodeSyncService::notifyUserRemovedFromGroup($this->userId, (int) $gid));
            }
        }
    }
}
