<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;

/**
 * v2_user 只保留账号字段；套餐字段已迁 v2_user_plan。
 * 套餐变更（开通/续费/重置/编辑）由各写路径显式派发 NodeUserSyncJob，
 * 此处只处理账号级字段（uuid/banned）变更。
 */
class UserObserver
{
  public bool $afterCommit = true;

  public function updated(User $user): void
  {
    if ($user->wasChanged(['uuid', 'banned'])) {
      NodeUserSyncJob::dispatch($user->id, 'updated');
    }
  }

  public function created(User $user): void
  {
    NodeUserSyncJob::dispatch($user->id, 'created');
  }

  public function deleted(User $user): void
  {
    NodeUserSyncJob::dispatch($user->id, 'deleted');
  }
}
