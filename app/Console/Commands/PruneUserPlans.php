<?php

namespace App\Console\Commands;

use App\Models\UserPlan;
use Illuminate\Console\Command;

/**
 * 清理长期耗尽的流量包行（每日独立 cron）。
 * - 只删 kind=2 且 exhausted_at < now-90 天的行；
 * - 时钟从耗尽日起算（退役/续买不重置时钟）；
 * - exhausted_at 为空的耗尽行先补打时间戳，下轮再删；
 * - cycle 行永不删；循环限量删。
 * 代价：行删后该包明细丢失（订单表记录不受影响）；没用完就过期的包默认不清。
 */
class PruneUserPlans extends Command
{
    protected $signature = 'fboard:prune-user-plans {--limit=1000 : 单次最多删除行数}';
    protected $description = '清理 90 天前耗尽的流量包实例行';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $now = time();
        $cutoff = $now - 90 * 86400;

        // 先补打：已耗尽但 exhausted_at 为空的行，打上当前时间戳下轮再删。
        $stamped = UserPlan::query()
            ->where('kind', UserPlan::KIND_PACK)
            ->whereNull('exhausted_at')
            ->whereRaw('u + d >= transfer_enable')
            ->update(['exhausted_at' => $now]);
        if ($stamped > 0) {
            $this->info("补打耗尽时间戳：{$stamped} 行");
        }

        $deleted = 0;
        do {
            $ids = UserPlan::query()
                ->where('kind', UserPlan::KIND_PACK)
                ->whereNotNull('exhausted_at')
                ->where('exhausted_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(min(500, $limit - $deleted))
                ->pluck('id')
                ->all();
            if (empty($ids)) {
                break;
            }
            $deleted += UserPlan::query()->whereIn('id', $ids)->delete();
        } while ($deleted < $limit);

        $this->info("清理耗尽包行：{$deleted} 行");
        return self::SUCCESS;
    }
}
