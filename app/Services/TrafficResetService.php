<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
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
    if (!$user->shouldResetTraffic()) {
      return false;
    }

    return $this->performReset($user, $triggerSource);
  }

  /**
   * Perform the traffic reset for a user.
   *
   * 多套餐模式：重置该用户所有活跃套餐实例；单套餐模式：重置用户（旧逻辑）。
   */
  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL): bool
  {
    if (UserPlanService::multiPlanEnabled()) {
      $instances = UserPlanService::getActiveInstances($user->id);
      $ok = true;
      foreach ($instances as $inst) {
        if (!$this->performResetForInstance($inst, $triggerSource)) {
          $ok = false;
        }
      }
      UserPlanService::syncUserAggregate($user->id);
      return $ok;
    }

    try {
      return DB::transaction(function () use ($user, $triggerSource) {
        $oldUpload = $user->u ?? 0;
        $oldDownload = $user->d ?? 0;
        $oldTotal = $oldUpload + $oldDownload;

        $nextResetTime = $this->calculateNextResetTime($user);

        $user->update([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => time(),
          'reset_count' => $user->reset_count + 1,
          'next_reset_at' => $nextResetTime ? $nextResetTime->timestamp : null,
        ]);

        $this->recordResetLog($user, [
          'reset_type' => $this->getResetTypeFromPlan($user->plan),
          'trigger_source' => $triggerSource,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
        ]);

        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);
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
   * 多套餐模式：重置单个套餐实例的流量。
   */
  public function performResetForInstance(\App\Models\UserPlan $instance, string $triggerSource = TrafficResetLog::SOURCE_MANUAL): bool
  {
    try {
      return DB::transaction(function () use ($instance, $triggerSource) {
        $oldUpload = $instance->u ?? 0;
        $oldDownload = $instance->d ?? 0;

        $nextResetTime = $this->calculateNextResetTimeForInstance($instance);

        $instance->update([
          'u' => 0,
          'd' => 0,
          'last_reset_at' => time(),
          'reset_count' => ($instance->reset_count ?? 0) + 1,
          'next_reset_at' => $nextResetTime ? $nextResetTime->timestamp : null,
        ]);

        $user = $instance->user;
        if ($user) {
          $this->recordResetLog($user, [
            'reset_type' => $this->getResetTypeFromPlan($instance->plan),
            'trigger_source' => $triggerSource,
            'old_upload' => $oldUpload,
            'old_download' => $oldDownload,
            'old_total' => $oldUpload + $oldDownload,
            'new_upload' => 0,
            'new_download' => 0,
            'new_total' => 0,
            'metadata' => ['user_plan_id' => $instance->id, 'plan_id' => $instance->plan_id],
          ]);
          $this->clearUserCache($user);
          HookManager::call('traffic.reset.after', ['user' => $user, 'user_plan_id' => $instance->id, 'plan_id' => $instance->plan_id]);
        }
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_plan_id' => $instance->id,
        'user_id' => $instance->user_id,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);
      return false;
    }
  }

  /**
   * 多套餐模式：计算单个套餐实例的下次重置时间（锚定实例自身的 plan 与 expired_at）。
   */
  public function calculateNextResetTimeForInstance(\App\Models\UserPlan $instance): ?Carbon
  {
    $plan = $instance->plan;
    if (
      !$plan
      || $plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) === Plan::RESET_TRAFFIC_NEVER)
      || $instance->expired_at === null
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
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyResetForTimestamp($instance->expired_at, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyResetForTimestamp($instance->expired_at, $now),
      default => null,
    };
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (
      !$user->plan
      || $user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) === Plan::RESET_TRAFFIC_NEVER)
      || $user->expired_at === NULL
    ) {
      return null;
    }

    $resetMethod = $user->plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
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
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];
    
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
   * 实例版按月重置：与 getNextMonthlyReset 同算法，入参为到期时间戳。
   */
  private function getNextMonthlyResetForTimestamp(int $expiredAtTs, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($expiredAtTs, config('app.timezone'));
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

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
   * 实例版按年重置：与 getNextYearlyReset 同算法，入参为到期时间戳。
   */
  private function getNextYearlyResetForTimestamp(int $expiredAtTs, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($expiredAtTs, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

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
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Logic:
   * 1. If the user has no expiration date, reset on January 1st of each year.
   * 2. If the user has an expiration date, use the month and day of that date as the yearly reset date.
   * 3. Prioritize the reset date in the current year if it has not passed yet.
   * 4. Handle the case of February 29th in a leap year.
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

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
    if (UserPlanService::multiPlanEnabled()) {
      return $this->batchCheckResetInstances($batchSize, $progressCallback);
    }

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
   * 多套餐模式：扫描到期的套餐实例并逐个重置。
   */
  public function batchCheckResetInstances(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $errors = [];
    $lastProcessedId = 0;

    try {
      do {
        $instances = \App\Models\UserPlan::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($q) {
            $q->where('expired_at', '>', time())->orWhereNull('expired_at');
          })
          ->whereHas('user', fn($q) => $q->where('banned', 0))
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($instances->isEmpty()) {
          break;
        }

        foreach ($instances as $inst) {
          try {
            if ($inst->next_reset_at !== null && $inst->next_reset_at <= time()) {
              if ($this->performResetForInstance($inst, TrafficResetLog::SOURCE_CRON)) {
                $totalResetCount++;
              }
            }
            $totalProcessedCount++;
            $lastProcessedId = $inst->id;
          } catch (\Exception $e) {
            $errors[] = ['user_plan_id' => $inst->id, 'error' => $e->getMessage()];
            $totalProcessedCount++;
            $lastProcessedId = $inst->id;
          }
        }

        if ($progressCallback) {
          $progressCallback(['total_processed' => $totalProcessedCount]);
        }
      } while (true);
    } catch (\Exception $e) {
      Log::error('Batch instance traffic reset failed', ['error' => $e->getMessage()]);
      $errors[] = ['type' => 'system_error', 'error' => $e->getMessage()];
    }

    return [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => round(microtime(true) - $startTime, 2),
    ];
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
    return $user->isActive() && $user->plan !== null;
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL);
  }
}