<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 删除 v2_user 的 9 个套餐冗余列，套餐数据唯一权威源 = v2_user_plan。
 *
 * up()：删列（不可逆的破坏性操作，回滚靠 down() 反填）。
 * down()：重建 9 列，并把 v2_user_plan 的有效实例聚合反填回 v2_user
 *         （配额/用量求和、到期取最晚、限速/设备取 max、单实例回填 plan/group、
 *           next_reset_at 取最早已有效实例）。与 User::getPlanAggregate() 同口径。
 *
 * 说明：
 * - next_reset_at 曾建 idx_next_reset_at，删列前先 dropIndex。
 * - 大表删列依赖 MySQL 8.0.29+ instant drop，低版本需低峰或 pt-osc。
 */
return new class extends Migration
{
    /** @var list<string> 待删/待恢复的套餐列 */
    private const COLUMNS = [
        'plan_id',
        'group_id',
        'transfer_enable',
        'u',
        'd',
        'expired_at',
        'next_reset_at',
        'speed_limit',
        'device_limit',
    ];

    public function up(): void
    {
        // 删除所有依赖待删列的索引（命名因老库/新库而异），否则 drop column 会失败。
        foreach (Schema::getIndexes('v2_user') as $index) {
            $indexName = $index['name'] ?? null;
            $indexCols = $index['columns'] ?? [];
            if (!$indexName || ($index['primary'] ?? false)) {
                continue;
            }
            if (!array_intersect($indexCols, self::COLUMNS)) {
                continue;
            }
            try {
                Schema::table('v2_user', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            } catch (\Throwable) {
                // 重复执行/历史差异，忽略。
            }
        }

        // SQLite 不支持一次 drop 多列：逐列删除。
        foreach (self::COLUMNS as $column) {
            Schema::table('v2_user', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->integer('plan_id')->nullable()->after('telegram_id');
            $table->integer('group_id')->nullable()->after('plan_id');
            $table->bigInteger('transfer_enable')->default(0)->after('group_id');
            $table->bigInteger('u')->default(0)->after('transfer_enable');
            $table->bigInteger('d')->default(0)->after('u');
            $table->bigInteger('expired_at')->nullable()->default(0)->after('d');
            $table->integer('next_reset_at')->nullable()->after('expired_at');
            $table->integer('speed_limit')->nullable()->after('next_reset_at');
            $table->integer('device_limit')->nullable()->after('speed_limit');
            $table->index('next_reset_at', 'idx_next_reset_at');
            // 还原 2023_12_12 建立的复合索引
            $table->index(['u', 'd', 'expired_at', 'group_id', 'banned', 'transfer_enable']);
        });

        // 从实例表聚合反填（与 User::getPlanAggregate() 同口径）。
        $now = time();
        DB::table('v2_user')->orderBy('id')->chunkById(500, function ($users) use ($now) {
            foreach ($users as $user) {
                $rows = DB::table('v2_user_plan')
                    ->where('user_id', $user->id)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('expired_at')->orWhere('expired_at', '>', $now);
                    })
                    ->get();

                $planIds = $rows->pluck('plan_id')->map(fn ($v) => (int) $v)->unique()->values();
                $groupIds = $rows->pluck('group_id')->map(fn ($v) => (int) $v)->unique()->values();
                $hasPermanent = $rows->contains(fn ($r) => $r->expired_at === null);
                $maxExpired = $rows->max(fn ($r) => $r->expired_at !== null ? (int) $r->expired_at : null);
                $maxSpeed = $rows->max(fn ($r) => $r->speed_limit !== null ? (int) $r->speed_limit : null);
                $maxDevice = $rows->max(fn ($r) => $r->device_limit !== null ? (int) $r->device_limit : null);
                $minNext = $rows->min(fn ($r) => $r->next_reset_at !== null ? (int) $r->next_reset_at : null);

                DB::table('v2_user')->where('id', $user->id)->update([
                    'plan_id' => $planIds->count() === 1 ? $planIds->first() : null,
                    'group_id' => $groupIds->count() === 1 ? $groupIds->first() : null,
                    'transfer_enable' => (int) $rows->sum(fn ($r) => (int) $r->transfer_enable),
                    'u' => (int) $rows->sum(fn ($r) => (int) $r->u),
                    'd' => (int) $rows->sum(fn ($r) => (int) $r->d),
                    'expired_at' => $rows->isEmpty() ? 0 : ($hasPermanent ? null : $maxExpired),
                    'next_reset_at' => $minNext !== null ? (int) $minNext : null,
                    'speed_limit' => $maxSpeed !== null ? (int) $maxSpeed : null,
                    'device_limit' => $maxDevice !== null ? (int) $maxDevice : null,
                ]);
            }
        });
    }
};
