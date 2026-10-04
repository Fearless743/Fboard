<?php

namespace App\Services;

use App\Enums\UserPlanKind;
use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\UserPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Plugin\HookManager;

/**
 * Service for handling traffic reset.
 */
class TrafficResetService
{
  /**
   * Check if a user's traffic should be reset and perform the reset.
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    if (UserPlan::isEnabled()) {
      return $this->checkAndResetUserInstances($user, $triggerSource);
    }
    if (!$user->shouldResetTraffic()) {
      return false;
    }

    // force=false：事务内拿到行锁后会再确认一次 next_reset_at。
    // cron（reset:traffic 每分钟）与用户访问面板（getUserTrafficInfo）
    // 会并发触发同一个重置；没有这次二次确认时，后到者会把先到者
    // 落地之后新产生的流量再清零一次（数据里表现为同 reset_time 的两条
    // 记录、一条 old_total=0）。
    return $this->performReset($user, $triggerSource, false);
  }

  /**
   * 多套餐：逐个检查用户名下 cycle 行，各行按自己的 next_reset_at 重置。
   */
  public function checkAndResetUserInstances(User $user, string $triggerSource): bool
  {
    $reset = false;
    $rows = UserPlan::query()
      ->where('user_id', $user->id)
      ->where('kind', UserPlan::KIND_CYCLE)
      ->orderBy('id')
      ->get();
    foreach ($rows as $row) {
      if ($this->resetInstance($row, $triggerSource, false)) {
        $reset = true;
      }
    }

    return $reset;
  }

  /**
   * 多套餐统一实例重置：cron/手动/订单调同一函数。
   * - 仅 cycle 行：按各自 next_reset_at 清 u/d 并推周期；
   * - pack 行不清零（用完即止，到期退出聚合），直接返回 false；
   * - force=false 时事务内二次确认（到期且有效才动）；
   * - 手动（force=true）对耗尽实例同样生效。
   */
  public function resetInstance(UserPlan $instance, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, bool $force = true): bool
  {
    $now = time();
    $resetDone = false;
    $userId = (int) $instance->user_id;

    try {
      $resetDone = DB::transaction(function () use ($instance, $triggerSource, $force, $now) {
        // 必须在事务内重读并加行锁：用调用方传进来的模型做读改写会读到
        // 事务外的陈旧快照。
        $fresh = UserPlan::query()->whereKey($instance->getKey())->lockForUpdate()->first();
        if (!$fresh) {
          return false;
        }
        if ($fresh->kind !== UserPlanKind::Cycle) {
          return false;
        }
        if (!$force && !$this->shouldResetInstance($fresh, $now)) {
          return false;
        }

        $oldUpload = (int) $fresh->u;
        $oldDownload = (int) $fresh->d;

        $plan = Plan::query()->find($fresh->plan_id);
        $nextResetTime = $this->calculateNextResetTimeForPlan($plan, $fresh->expired_at);

        $fresh->forceFill([
          'u' => 0,
          'd' => 0,
          'next_reset_at' => $nextResetTime ? $nextResetTime->timestamp : null,
        ])->save();

        // 实例重置同步刷新 user.last_reset_at：TrafficFetchJob 的 reportTs
        // 防回拨依赖它丢弃重置前收到的 report。用 query 更新，不触发 User 事件。
        User::query()->whereKey($fresh->user_id)->update(['last_reset_at' => $now]);

        TrafficResetLog::create([
          'user_id' => $fresh->user_id,
          'reset_type' => $this->getResetTypeFromPlan($plan),
          'reset_time' => now(),
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldUpload + $oldDownload,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
          'trigger_source' => $triggerSource,
          'metadata' => [
            'user_plan_id' => $fresh->id,
            'plan_id' => $fresh->plan_id,
          ],
        ]);

        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_plan_id' => $instance->id,
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }

    if ($resetDone) {
      // 事务外：清缓存、调钩子、通知节点把恢复可用的用户加回去。
      // 传 User 模型给钩子/缓存清理，保持与单套餐一致的形态。
      $user = User::query()->whereKey($userId)->first(['id', 'token']);
      if ($user) {
        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);
      }
      NodeUserSyncJob::dispatch($userId, 'updated');
    }

    return $resetDone;
  }

  /**
   * 实例是否到重置点：有效（未到期）且 next_reset_at 已到。
   * 过期行即使 next_reset_at 到期也不动（等续购按新周期复用）。
   */
  public function shouldResetInstance(UserPlan $instance, ?int $now = null): bool
  {
    $now ??= time();

    return $instance->kind === UserPlanKind::Cycle
      && $instance->isActive($now)
      && $instance->next_reset_at !== null
      && (int) $instance->next_reset_at <= $now;
  }

  /**
   * Perform the traffic reset for a user.
   *
   * @param bool $force true = 无条件重置（手动/API/订单/礼品卡）；
   *                    false = 事务内二次确认 next_reset_at 是否到期。
   */
  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, bool $force = true): bool
  {
    try {
      return DB::transaction(function () use ($user, $triggerSource, $force) {
        // 必须在事务内重读并加行锁：用调用方传进来的模型做读改写会读到
        // 事务外的陈旧快照。
        $fresh = User::whereKey($user->getKey())->lockForUpdate()->first();
        if (!$fresh) {
          return false;
        }

        if (!$force && !$fresh->shouldResetTraffic()) {
          return false;
        }

        $oldUpload = (int) ($fresh->u ?? 0);
        $oldDownload = (int) ($fresh->d ?? 0);
        $oldTotal = $oldUpload + $oldDownload;

        $nextResetTime = $this->calculateNextResetTime($fresh);
        $resetAt = time();

        // 一次 UPDATE 完成清零 + 计数自增，reset_count 用 SQL 表达式避免读改写。
        // 直接调用方模型的 update() 会把模型上其它脏字段一并写库，
        // 那样订单流程里的 transfer_enable 会被隐式落库，语义不清。
        $fresh->setAttribute('reset_count', DB::raw('COALESCE(reset_count, 0) + 1'));
        $fresh->forceFill([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => $resetAt,
          'next_reset_at' => $nextResetTime ? $nextResetTime->timestamp : null,
        ])->save();

        // 把重置结果同步回调用方的模型实例，只同步这几个字段，
        // 保留订单流程尚未落库的 transfer_enable / plan_id 等脏字段。
        $user->u = 0;
        $user->d = 0;
        $user->last_reset_at = $resetAt;
        $user->next_reset_at = $nextResetTime ? $nextResetTime->timestamp : null;
        $user->reset_count = ((int) $fresh->getRawOriginal('reset_count')) + 1;

        $this->recordResetLog($fresh, [
          'reset_type' => $this->getResetTypeFromPlan($fresh->plan),
          'trigger_source' => $triggerSource,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
        ]);

        $this->clearUserCache($fresh);
        HookManager::call('traffic.reset.after', $fresh);
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $user->id,
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (!$user->plan) {
      return null;
    }

    return $this->calculateNextResetTimeForPlan($user->plan, $user->expired_at);
  }

  /**
   * 按 (plan, expired_at) 计算下次重置时间。
   * 多套餐实例重置与订单开通共用此入口：cycle 行按各自 expired_at 锚定周期。
   */
  public function calculateNextResetTimeForPlan(?Plan $plan, ?int $expiredAt): ?Carbon
  {
    if (
      !$plan
      || $plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) === Plan::RESET_TRAFFIC_NEVER)
      || $expiredAt === NULL
    ) {
      return null;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyResetAt($expiredAt, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyResetAt($expiredAt, $now),
      default => null,
    };
  }

  /**
   * Get the first day of the next month.
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addMonth()->startOfMonth();
  }

  /**
   * Get the next monthly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on the 1st of each month.
   * 2. If the user has an expiration date, use the day of that date as the monthly reset day.
   * 3. Prioritize the reset day in the current month if it has not passed yet.
   * 4. Handle cases where the day does not exist in a month (e.g., 31st in February).
   */
  /**
   * Get the next monthly reset time anchored at the given expiration timestamp.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on the 1st of each month.
   * 2. If the user has an expiration date, use the day of that date as the monthly reset day.
   * 3. Prioritize the reset day in the current month if it has not passed yet.
   * 4. Handle cases where the day does not exist in a month (e.g., 31st in February).
   */
  private function getNextMonthlyResetAt(int $expiredAt, Carbon $from): Carbon
  {
    $expired = Carbon::createFromTimestamp($expiredAt, config('app.timezone'));
    $resetDay = $expired->day;
    $resetTime = [$expired->hour, $expired->minute, $expired->second];
    
    $currentMonthTarget = $from->copy()->day($resetDay)->setTime(...$resetTime);
    if ($currentMonthTarget->timestamp > $from->timestamp) {
      return $currentMonthTarget;
    }
    
    $nextMonthTarget = $from->copy()->startOfMonth()->addMonths(1)->day($resetDay)->setTime(...$resetTime);
    
    if ($nextMonthTarget->month !== ($from->month % 12) + 1) {
      $nextMonth = ($from->month % 12) + 1;
      $nextYear = $from->year + ($from->month === 12 ? 1 : 0);
      $lastDayOfNextMonth = Carbon::create($nextYear, $nextMonth, 1)->endOfMonth()->day;
      $targetDay = min($resetDay, $lastDayOfNextMonth);
      $nextMonthTarget = Carbon::create($nextYear, $nextMonth, $targetDay)->setTime(...$resetTime);
    }
    
    return $nextMonthTarget;
  }

  /**
   * Get the first day of the next year.
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->addYear()->startOfYear();
  }

  /**
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on January 1st of each year.
   * 2. If the user has an expiration date, use the month and day of that date as the yearly reset date.
   * 3. Prioritize the reset date in the current year if it has not passed yet.
   * 4. Handle the case of February 29th in a leap year.
   */
  /**
   * Get the next yearly reset time anchored at the given expiration timestamp.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on January 1st of each year.
   * 2. If the user has an expiration date, use the month and day of that date as the yearly reset date.
   * 3. Prioritize the reset date in the current year if it has not passed yet.
   * 4. Handle the case of February 29th in a leap year.
   */
  private function getNextYearlyResetAt(int $expiredAt, Carbon $from): Carbon
  {
    $expired = Carbon::createFromTimestamp($expiredAt, config('app.timezone'));
    $resetMonth = $expired->month;
    $resetDay = $expired->day;
    $resetTime = [$expired->hour, $expired->minute, $expired->second];

    $currentYearTarget = $from->copy()->month($resetMonth)->day($resetDay)->setTime(...$resetTime);
    if ($currentYearTarget->timestamp > $from->timestamp) {
      return $currentYearTarget;
    }
    
    $nextYearTarget = $from->copy()->startOfYear()->addYears(1)->month($resetMonth)->day($resetDay)->setTime(...$resetTime);
    
    if ($nextYearTarget->month !== $resetMonth) {
      $nextYear = $from->year + 1;
      $lastDayOfMonth = Carbon::create($nextYear, $resetMonth, 1)->endOfMonth()->day;
      $targetDay = min($resetDay, $lastDayOfMonth);
      $nextYearTarget = Carbon::create($nextYear, $resetMonth, $targetDay)->setTime(...$resetTime);
    }
    
    return $nextYearTarget;
  }


  /**
   * Record the traffic reset log.
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * Get the reset type from the user's plan.
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * Clear user-related cache.
   */
  private function clearUserCache(User $user): void
  {
    $cacheKeys = [
      "user_traffic_{$user->id}",
      "user_reset_status_{$user->id}",
      "user_subscription_{$user->token}",
    ];

    foreach ($cacheKeys as $key) {
      Cache::forget($key);
    }
  }

  /**
   * Batch check and reset users. Processes all eligible users in batches.
   */
  public function batchCheckReset(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $batchNumber = 1;
    $errors = [];
    $lastProcessedId = 0;

    HookManager::call('traffic.batch_reset.before', [
      'batch_size' => $batchSize,
    ]);

    try {
      do {
        $users = User::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($query) {
            $query->where('expired_at', '>', time())
              ->orWhereNull('expired_at');
          })
          ->where('banned', 0)
          ->whereNotNull('plan_id')
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($users->isEmpty()) {
          break;
        }

        $batchResetCount = 0;

        if ($progressCallback) {
          $progressCallback([
            'batch_number' => $batchNumber,
            'batch_size' => $users->count(),
            'total_processed' => $totalProcessedCount,
          ]);
        }

        foreach ($users as $user) {
          try {
            if ($this->checkAndReset($user, TrafficResetLog::SOURCE_CRON)) {
              $batchResetCount++;
              $totalResetCount++;
            }
            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          } catch (\Exception $e) {
            $error = [
              'user_id' => $user->id,
              'email' => $user->email,
              'error' => $e->getMessage(),
              'batch' => $batchNumber,
              'timestamp' => now()->toDateTimeString(),
            ];
            $batchErrors[] = $error;
            $errors[] = $error;

            Log::error('User traffic reset failed', $error);

            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          }
        }

        $batchNumber++;

        if ($batchNumber % 10 === 0) {
          gc_collect_cycles();
        }

        if ($batchNumber % 5 === 0) {
          usleep(100000);
        }

      } while (true);

    } catch (\Exception $e) {
      Log::error('Batch traffic reset task failed with an exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_processed' => $totalProcessedCount,
        'total_reset' => $totalResetCount,
        'last_processed_id' => $lastProcessedId,
      ]);

      $errors[] = [
        'type' => 'system_error',
        'error' => $e->getMessage(),
        'batch' => $batchNumber,
        'last_processed_id' => $lastProcessedId,
        'timestamp' => now()->toDateTimeString(),
      ];
    }

    $totalDuration = round(microtime(true) - $startTime, 2);

    $result = [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'total_batches' => $batchNumber - 1,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => $totalDuration,
      'batch_size' => $batchSize,
      'last_processed_id' => $lastProcessedId,
      'completed_at' => now()->toDateTimeString(),
    ];

    HookManager::call('traffic.batch_reset.after', $result);

    return $result;
  }

  /**
   * Set the initial reset time for a new user.
   */
  public function setInitialResetTime(User $user): void
  {
    if ($user->next_reset_at !== null) {
      return;
    }

    $nextResetTime = $this->calculateNextResetTime($user);

    if ($nextResetTime) {
      $user->update(['next_reset_at' => $nextResetTime->timestamp]);
    }
  }

  /**
   * Get the user's traffic reset history.
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return $user->trafficResetLogs()
      ->orderBy('reset_time', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * Check if the user is eligible for traffic reset.
   */
  public function canReset(User $user): bool
  {
    if (UserPlan::isEnabled()) {
      if ($user->banned) {
        return false;
      }
      $query = UserPlan::query()
        ->where('user_id', $user->id)
        ->where('kind', UserPlan::KIND_CYCLE);
      UserPlan::applyActive($query, time());

      return $query->exists();
    }
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (UserPlan::isEnabled()) {
      // 手动重置该用户全部有效 cycle 行（force=true，耗尽实例同样生效）；
      // pack 行不清零。
      if ($user->banned) {
        return false;
      }
      $now = time();
      $rows = UserPlan::query()
        ->where('user_id', $user->id)
        ->where('kind', UserPlan::KIND_CYCLE)
        ->orderBy('id')
        ->get()
        ->filter(fn (UserPlan $row) => $row->isActive($now))
        ->values();
      $reset = false;
      foreach ($rows as $row) {
        if ($this->resetInstance($row, TrafficResetLog::SOURCE_MANUAL, true)) {
          $reset = true;
        }
      }

      return $reset;
    }
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL);
  }
}