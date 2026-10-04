<?php

namespace App\Console\Commands;

use App\Models\UserPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 套餐实例表一致性检查（只报不改）。
 * 主表 v2_user 已无套餐列，故不再做「实例聚合 vs 主表快照」对账，
 * 只扫实例表自洽问题：
 * - 同一 (user_id, plan_id, kind=1) 出现多行（一行性靠流程保证，这里兜底告警）；
 * - pack 行残留 next_reset_at（pack 不清零/不重置，应为 null）；
 * - 配额为负、用量为负等明显脏数据。
 * 发现问题返回 FAILURE（供 cron 告警），始终不写库。
 */
class CheckUserPlans extends Command
{
    protected $signature = 'fboard:check-user-plans {--show=20 : 最多展示差异数}';
    protected $description = '套餐实例表一致性检查';

    public function handle(): int
    {
        $show = max(1, (int) $this->option('show'));
        $issues = 0;

        // 1) cycle 重复行
        $dupeTotal = (int) (DB::table('v2_user_plan')
            ->select([DB::raw('COUNT(*) AS t')])
            ->fromSub(function ($q) {
                $q->from('v2_user_plan')
                    ->select([DB::raw('1')])
                    ->where('kind', UserPlan::KIND_CYCLE)
                    ->groupBy(['user_id', 'plan_id'])
                    ->havingRaw('COUNT(*) > 1');
            }, 'd')
            ->value('t') ?? 0);
        if ($dupeTotal > 0) {
            $issues += $dupeTotal;
            $this->error("cycle 重复行 {$dupeTotal} 组（user_id, plan_id）：");
            DB::table('v2_user_plan')
                ->select(['user_id', 'plan_id', DB::raw('COUNT(*) AS c')])
                ->where('kind', UserPlan::KIND_CYCLE)
                ->groupBy(['user_id', 'plan_id'])
                ->havingRaw('COUNT(*) > 1')
                ->limit($show)
                ->get()
                ->each(fn ($d) => $this->line("  user={$d->user_id} plan={$d->plan_id} rows={$d->c}"));
        } else {
            $this->info('cycle 重复行：无');
        }

        // 2) pack 行残留 next_reset_at
        $packNext = (int) DB::table('v2_user_plan')
            ->where('kind', UserPlan::KIND_PACK)
            ->whereNotNull('next_reset_at')
            ->count();
        if ($packNext > 0) {
            $issues += $packNext;
            $this->error("pack 行残留 next_reset_at：{$packNext} 行（应为 null）");
        } else {
            $this->info('pack 行 next_reset_at：无残留');
        }

        // 3) 明显脏数据：负配额 / 负用量
        $badQuota = (int) DB::table('v2_user_plan')->where('transfer_enable', '<', 0)->count();
        $badUsage = (int) DB::table('v2_user_plan')
            ->where(fn ($q) => $q->where('u', '<', 0)->orWhere('d', '<', 0))
            ->count();
        if ($badQuota > 0 || $badUsage > 0) {
            $issues += $badQuota + $badUsage;
            $this->error("脏数据：负配额 {$badQuota} 行，负用量 {$badUsage} 行");
        } else {
            $this->info('配额/用量：无非负异常');
        }

        if ($issues > 0) {
            $this->error("发现 {$issues} 个问题（只报不改，请人工处理）");
            return self::FAILURE;
        }
        $this->info('实例表一致性检查通过');
        return self::SUCCESS;
    }
}
