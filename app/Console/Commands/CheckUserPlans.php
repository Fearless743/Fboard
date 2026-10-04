<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 实例表一致性检查 + 与主表快照对账（常驻定时任务，QP：只报不改）。
 * - 重复行：同一 (user_id, plan_id, kind=1) 出现多行（一行性靠流程保证，这里兜底告警）；
 * - 对账：有实例行的用户，实例聚合必须 == 主表快照（9 列冻结验证）；
 *   无实例行的用户跳过（未迁移，沿用主表）。
 * 发现问题返回 FAILURE（供 cron 告警），始终不写库。
 */
class CheckUserPlans extends Command
{
    protected $signature = 'fboard:check-user-plans {--limit=20000 : 最多对账用户数} {--show=20 : 最多展示差异数}';
    protected $description = '套餐实例表一致性检查与主表对账';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $show = max(1, (int) $this->option('show'));
        $issues = 0;

        // 1) cycle 重复行
        $dupes = DB::table('v2_user_plan')
            ->select(['user_id', 'plan_id', DB::raw('COUNT(*) AS c')])
            ->where('kind', UserPlan::KIND_CYCLE)
            ->groupBy(['user_id', 'plan_id'])
            ->havingRaw('COUNT(*) > 1')
            ->limit($show)
            ->get();
        $dupeTotal = DB::table('v2_user_plan')
            ->select([DB::raw('COUNT(*) AS t')])
            ->fromSub(function ($q) {
                $q->from('v2_user_plan')
                    ->select([DB::raw('1')])
                    ->where('kind', UserPlan::KIND_CYCLE)
                    ->groupBy(['user_id', 'plan_id'])
                    ->havingRaw('COUNT(*) > 1');
            }, 'd')
            ->value('t') ?? 0;
        if ($dupeTotal > 0) {
            $issues += (int) $dupeTotal;
            $this->error("cycle 重复行 {$dupeTotal} 组（user_id, plan_id）：");
            foreach ($dupes as $d) {
                $this->line("  user={$d->user_id} plan={$d->plan_id} rows={$d->c}");
            }
        } else {
            $this->info('cycle 重复行：无');
        }

        // 2) 聚合 == 主表快照
        $checked = 0;
        $mismatched = 0;
        User::query()->whereIn('id', function ($q) {
            $q->select('user_id')->from('v2_user_plan');
        })->orderBy('id')->limit($limit)->chunk(500, function ($users) use (&$checked, &$mismatched, &$issues, $show) {
            $ids = $users->pluck('id')->all();
            $grouped = UserPlan::query()->whereIn('user_id', $ids)->get()->groupBy('user_id');
            foreach ($users as $user) {
                $checked++;
                $rows = $grouped->get($user->id, collect());
                $active = $rows->filter(fn (UserPlan $r) => $r->isActive(time()))->values();
                $quota = (int) $active->sum(fn (UserPlan $r) => (int) $r->transfer_enable);
                $used = (int) $active->sum(fn (UserPlan $r) => (int) $r->u + (int) $r->d);
                $permanent = $active->contains(fn (UserPlan $r) => $r->expired_at === null);
                $maxExpired = $active->max(fn (UserPlan $r) => $r->expired_at !== null ? (int) $r->expired_at : null);
                $expectedExpired = $active->isEmpty() ? null : ($permanent ? null : $maxExpired);
                $masterExpired = $user->expired_at ? (int) $user->expired_at : null;
                $maxSpeed = $active->max(fn (UserPlan $r) => $r->speed_limit !== null ? (int) $r->speed_limit : null);
                $maxDevice = $active->max(fn (UserPlan $r) => $r->device_limit !== null ? (int) $r->device_limit : null);

                $diff = [];
                if ($quota !== (int) $user->transfer_enable) {
                    $diff[] = "quota {$quota} != master {$user->transfer_enable}";
                }
                if ($used !== ((int) $user->u + (int) $user->d)) {
                    $diff[] = 'used mismatch';
                }
                if ($expectedExpired !== $masterExpired) {
                    $diff[] = "expired {$expectedExpired} != master {$masterExpired}";
                }
                $masterSpeed = $user->speed_limit !== null ? (int) $user->speed_limit : null;
                $masterDevice = $user->device_limit !== null ? (int) $user->device_limit : null;
                if (($maxSpeed !== null ? (int) $maxSpeed : null) !== $masterSpeed) {
                    $diff[] = 'speed mismatch';
                }
                if (($maxDevice !== null ? (int) $maxDevice : null) !== $masterDevice) {
                    $diff[] = 'device mismatch';
                }
                if (!empty($diff)) {
                    $mismatched++;
                    $issues++;
                    if ($mismatched <= $show) {
                        $this->line("  user={$user->id}: " . implode('; ', $diff));
                    }
                }
            }
        });

        $this->info("对账用户：{$checked}，差异：{$mismatched}");
        if ($issues > 0) {
            $this->error("发现 {$issues} 个问题（只报不改，请人工处理）");
            return self::FAILURE;
        }
        $this->info('对账零差异');
        return self::SUCCESS;
    }
}
