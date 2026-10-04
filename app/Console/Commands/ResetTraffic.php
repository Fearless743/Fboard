<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\UserPlan;
use App\Models\TrafficResetLog;
use App\Services\TrafficResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetTraffic extends Command
{
  protected $signature = 'reset:traffic {--fix-null : 修正模式，重新计算next_reset_at为null的用户} {--force : 强制模式，重新计算所有用户的重置时间}';

  protected $description = '流量重置 - 处理所有需要重置的用户';

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
    parent::__construct();
  }

  public function handle(): int
  {
    $fixNull = $this->option('fix-null');
    $force = $this->option('force');

    $this->info('🚀 开始执行流量重置任务...');

    if ($fixNull) {
      $this->warn('🔧 修正模式 - 将重新计算next_reset_at为null的用户');
    } elseif ($force) {
      $this->warn('⚡ 强制模式 - 将重新计算所有用户的重置时间');
    }

    try {
      $result = $fixNull ? $this->performFix() : ($force ? $this->performForce() : $this->performReset());
      $this->displayResults($result, $fixNull || $force);
      return self::SUCCESS;

    } catch (\Exception $e) {
      $this->error("❌ 任务执行失败: {$e->getMessage()}");

      Log::error('流量重置命令执行失败', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return self::FAILURE;
    }
  }

  private function displayResults(array $result, bool $isSpecialMode): void
  {
    $this->info("✅ 任务完成！\n");

    if ($isSpecialMode) {
      $this->displayFixResults($result);
    } else {
      $this->displayExecutionResults($result);
    }
  }

  private function displayFixResults(array $result): void
  {
    $this->info("📊 修正结果统计:");
    $this->info("🔍 发现用户总数: {$result['total_found']}");
    $this->info("✅ 成功修正数量: {$result['total_fixed']}");
    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn("详细错误信息请查看日志");
    } else {
      $this->info("✨ 无错误发生");
    }

    if ($result['total_found'] > 0) {
      $avgTime = round($result['duration'] / $result['total_found'], 4);
      $this->info("⚡ 平均处理速度: {$avgTime} 秒/用户");
    }
  }



  private function displayExecutionResults(array $result): void
  {
    $this->info("📊 执行结果统计:");
    $this->info("👥 处理用户总数: {$result['total_processed']}");
    $this->info("🔄 重置用户数量: {$result['total_reset']}");
    $this->info("⏱️  总执行时间: {$result['duration']} 秒");

    if ($result['error_count'] > 0) {
      $this->warn("⚠️  错误数量: {$result['error_count']}");
      $this->warn("详细错误信息请查看日志");
    } else {
      $this->info("✨ 无错误发生");
    }

    if ($result['total_processed'] > 0) {
      $avgTime = round($result['duration'] / $result['total_processed'], 4);
      $this->info("⚡ 平均处理速度: {$avgTime} 秒/用户");
    }
  }

  private function performReset(): array
  {
    // 实例表是唯一数据源：无条件扫描实例行。
    return $this->performResetMulti();
  }

  private function performFix(): array
  {
    $startTime = microtime(true);
    $rows = $this->getNullResetTimeInstances();

    if ($rows->isEmpty()) {
      $this->info("✅ 没有发现next_reset_at为null的实例行");
      return [
        'total_found' => 0,
        'total_fixed' => 0,
        'error_count' => 0,
        'duration' => round(microtime(true) - $startTime, 2),
      ];
    }

    $this->info("🔧 发现 {$rows->count()} 个next_reset_at为null的实例行，开始修正...");

    return $this->recalcInstances($rows, $startTime, '修正实例next_reset_at失败');
  }

  private function performForce(): array
  {
    $startTime = microtime(true);
    $rows = UserPlan::where('kind', UserPlan::KIND_CYCLE)
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->get();

    if ($rows->isEmpty()) {
      $this->info("✅ 没有发现需要处理的实例行");
      return [
        'total_found' => 0,
        'total_fixed' => 0,
        'error_count' => 0,
        'duration' => round(microtime(true) - $startTime, 2),
      ];
    }

    $this->info("⚡ 发现 {$rows->count()} 个实例行，开始重新计算重置时间...");

    return $this->recalcInstances($rows, $startTime, '强制重新计算实例next_reset_at失败');
  }

  /**
   * 重算给定 cycle 行的 next_reset_at（锚定各自 expired_at，主表已无该列）。
   */
  private function recalcInstances($rows, float $startTime, string $logMessage): array
  {
    $fixedCount = 0;
    $errors = [];

    foreach ($rows as $row) {
      try {
        $plan = Plan::find($row->plan_id);
        $nextResetTime = $this->trafficResetService->calculateNextResetTimeForPlan($plan, $row->expired_at);
        if ($nextResetTime) {
          $row->next_reset_at = $nextResetTime->timestamp;
          $row->save();
          $fixedCount++;
        }
      } catch (\Exception $e) {
        $errors[] = [
          'user_plan_id' => $row->id,
          'user_id' => $row->user_id,
          'error' => $e->getMessage(),
        ];
        Log::error($logMessage, [
          'user_plan_id' => $row->id,
          'error' => $e->getMessage(),
        ]);
      }
    }

    return [
      'total_found' => $rows->count(),
      'total_fixed' => $fixedCount,
      'error_count' => count($errors),
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }



  /**
   * 扫描到期的 cycle 实例行（各行独立 next_reset_at），
   * 调统一 resetInstance（force=false，事务内二次确认）。
   */
  private function performResetMulti(): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessed = 0;
    $errors = [];
    $lastId = 0;
    $now = time();

    $this->info('多套餐模式：扫描实例行...');

    do {
      $rows = UserPlan::query()
        ->where('kind', UserPlan::KIND_CYCLE)
        ->whereNotNull('next_reset_at')
        ->where('next_reset_at', '<=', $now)
        ->where('id', '>', $lastId)
        ->where(function ($query) use ($now) {
          $query->whereNull('expired_at')->orWhere('expired_at', '>', $now);
        })
        ->whereHas('user', fn ($q) => $q->where('banned', 0))
        ->orderBy('id')
        ->limit(500)
        ->get();

      if ($rows->isEmpty()) {
        break;
      }

      $this->info("找到 {$rows->count()} 个待重置的实例行");

      foreach ($rows as $row) {
        $lastId = (int) $row->id;
        $totalProcessed++;
        try {
          $totalResetCount += (int) $this->trafficResetService->resetInstance($row, TrafficResetLog::SOURCE_CRON, false);
        } catch (\Exception $e) {
          $errors[] = [
            'user_plan_id' => $row->id,
            'user_id' => $row->user_id,
            'error' => $e->getMessage(),
          ];
          Log::error('套餐实例流量重置失败', [
            'user_plan_id' => $row->id,
            'user_id' => $row->user_id,
            'error' => $e->getMessage(),
          ]);
        }
      }
    } while (true);

    if ($totalProcessed === 0) {
      $this->info("😴 当前没有需要重置的实例行");
    }

    return [
      'total_processed' => $totalProcessed,
      'total_reset' => $totalResetCount,
      'error_count' => count($errors),
      'duration' => round(microtime(true) - $startTime, 2),
    ];
  }



  private function getNullResetTimeInstances()
  {
    return UserPlan::where('kind', UserPlan::KIND_CYCLE)
      ->whereNull('next_reset_at')
      ->where(function ($query) {
        $query->where('expired_at', '>', time())
          ->orWhereNull('expired_at');
      })
      ->whereHas('user', fn ($q) => $q->where('banned', 0))
      ->get();
  }

}