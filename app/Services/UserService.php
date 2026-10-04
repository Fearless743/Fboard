<?php

namespace App\Services;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use App\Models\TrafficResetLog;
use App\Utils\Helper;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /**
     * Get the remaining days until the next traffic reset for a user.
     * This method reuses the TrafficResetService logic for consistency.
     */
    public function getResetDay(User $user): ?int
    {
        // 实例表是唯一数据源：取全部有效 cycle 行最早的下次重置，还有多少天。
        $rows = UserPlan::query()->where('user_id', $user->id)->get();
        $now = time();
        $next = null;
        foreach ($rows as $row) {
            if (!$row->isActive($now) || $row->next_reset_at === null) {
                continue;
            }
            $ts = (int) $row->next_reset_at;
            if ($next === null || $ts < $next) {
                $next = $ts;
            }
        }
        if ($next === null) {
            return null;
        }
        if ($next <= $now) {
            return 0;
        }

        return (int) ceil(($next - $now) / 86400);
    }

    public function isAvailable(User $user)
    {
        return $user->isAvailable();
    }

    public function getAvailableUsers()
    {
        // 实例表是唯一数据源：持有有效实例且聚合剩余 > 0。
        return User::query()
            ->where('banned', 0)
            ->wherePlanAvailable()
            ->get();
    }

    public function getUnAvailbaleUsers()
    {
        // 无有效实例（未迁移/无套餐/全部过期）的用户。
        $now = time();

        return User::query()
            ->whereNotExists(function ($q) use ($now) {
                $q->selectRaw('1')->from('v2_user_plan')
                    ->whereColumn('v2_user_plan.user_id', 'v2_user.id')
                    ->where(function ($w) use ($now) {
                        $w->whereNull('expired_at')->orWhere('expired_at', '>', $now);
                    });
            })
            ->get();
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function getAllUsers()
    {
        return User::all();
    }

    public function addBalance(int $userId, int $balance): bool
    {
        $user = User::lockForUpdate()->find($userId);
        if (!$user) {
            return false;
        }
        $user->balance = $user->balance + $balance;
        if ($user->balance < 0) {
            return false;
        }
        if (!$user->save()) {
            return false;
        }
        return true;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        $order = Order::whereIn('status', [0, 1])
            ->where('user_id', $userId)
            ->first();
        if (!$order) {
            return false;
        }
        return true;
    }

    /**
     * 单个 traffic_fetch Job 内的串行写入上限。
     *
     * Job 内部是「每个用户一条 UPDATE」，chunk 越大越容易撞上 job timeout
     * 被整批丢弃。原值 1000 在高峰期会频繁超时。
     */
    public const TRAFFIC_CHUNK_SIZE = 200;

    public function trafficFetch(Server $server, string $protocol, array $data, ?int $reportTs = null)
    {
        $server->rate = $server->getCurrentRate();
        $server = $server->toArray();

        list($server, $protocol, $data) = HookManager::filter('traffic.process.before', [$server, $protocol, $data]);
        // Compatible with legacy hook
        list($server, $protocol, $data) = HookManager::filter('traffic.before_process', [$server, $protocol, $data]);

        $timestamp = strtotime(date('Y-m-d'));
        // 面板收到 report 的时刻：Job 靠它判断这份流量属于重置前还是重置后。
        $reportTs = $reportTs ?? time();
        collect($data)->chunk(self::TRAFFIC_CHUNK_SIZE)->each(function ($chunk) use ($timestamp, $reportTs, $server, $protocol) {
            TrafficFetchJob::dispatch($server, $chunk->toArray(), $protocol, $timestamp, $reportTs);
            StatUserJob::dispatch($server, $chunk->toArray(), $protocol, 'd');
            StatServerJob::dispatch($server, $chunk->toArray(), $protocol, 'd');
        });
    }

    /**
     * 获取用户流量信息（增加重置检查）
     */
    public function getUserTrafficInfo(User $user): array
    {
        // 检查是否需要重置流量
        app(TrafficResetService::class)->checkAndReset($user, TrafficResetLog::SOURCE_USER_ACCESS);

        // 重新获取用户数据（可能已被重置）
        $user->refresh();
        // 同一聚合入口：legacy 字段名返回计算值（仅内存，不落库）。
        $upload = 0;
        $download = 0;
        $quota = 0;
        $nextResetAt = null;
        $rows = UserPlan::query()->where('user_id', $user->id)->get();
        $now = time();
        foreach ($rows as $row) {
            if (!$row->isActive($now)) {
                continue;
            }
            $upload += (int) $row->u;
            $download += (int) $row->d;
            $quota += (int) $row->transfer_enable;
            if ($row->next_reset_at !== null && ($nextResetAt === null || (int) $row->next_reset_at < $nextResetAt)) {
                $nextResetAt = (int) $row->next_reset_at;
            }
        }
        $used = $upload + $download;

        return [
            'upload' => $upload,
            'download' => $download,
            'total_used' => $used,
            'total_available' => $quota,
            'remaining' => max(0, $quota - $used),
            'usage_percentage' => $quota > 0 ? min(100, ($used / $quota) * 100) : 0,
            'next_reset_at' => $nextResetAt,
            'last_reset_at' => $user->last_reset_at,
            'reset_count' => $user->reset_count,
        ];
    }

    /**
     * 创建用户
     */
    public function createUser(array $data): User
    {
        $user = new User();

        // 基本信息
        $user->email = $data['email'];
        $user->password = isset($data['password'])
            ? Hash::make($data['password'])
            : Hash::make($data['email']);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        // 默认设置
        $user->remind_expire = admin_setting('default_remind_expire', 1);
        $user->remind_traffic = admin_setting('default_remind_traffic', 1);

        // 可选账号字段
        $this->setOptionalFields($user, $data);

        // 处理计划：只暂存意图，实例行在 save 后由 seedInitialPlanRow() 创建。
        if (isset($data['plan_id'])) {
            $this->prepareInitialPlan($user, (int) $data['plan_id'], $data['expired_at'] ?? null);
        } else {
            $this->prepareTryOutPlan($user);
        }

        return $user;
    }

    /**
     * 设置可选字段（仅账号/关系字段）。
     * 套餐字段（plan_id/group_id/transfer_enable/expired_at/speed_limit/device_limit）
     * 已迁到 v2_user_plan，由 draftInitialPlan + seedInitialPlanRow() 处理。
     */
    private function setOptionalFields(User $user, array $data): void
    {
        $optionalFields = ['invite_user_id', 'telegram_id'];

        foreach ($optionalFields as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }
    }

    /**
     * 注册/批量生成后（用户已 save）调用：按 draftInitialPlan 建 cycle 首行。
     * 主表已无套餐列，意图只在内存 draft 中，这里落到 v2_user_plan。
     */
    public function seedInitialPlanRow(User $user): void
    {
        $draft = $user->draftInitialPlan;
        if (!$draft || empty($draft['plan_id']) || (int) $draft['transfer_enable'] <= 0) {
            return;
        }
        $exists = UserPlan::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $draft['plan_id'])
            ->where('kind', UserPlan::KIND_CYCLE)
            ->exists();
        if ($exists) {
            return;
        }

        $plan = Plan::find($draft['plan_id']);
        $expiredAt = $draft['expired_at'] !== null ? (int) $draft['expired_at'] : null;
        $row = new UserPlan();
        $row->forceFill([
            'user_id' => $user->id,
            'plan_id' => $draft['plan_id'],
            'kind' => UserPlan::KIND_CYCLE,
            'group_id' => $draft['group_id'],
            'order_ids' => [],
            'transfer_enable' => (int) $draft['transfer_enable'],
            'u' => 0,
            'd' => 0,
            'expired_at' => $expiredAt,
            'speed_limit' => $draft['speed_limit'],
            'device_limit' => $draft['device_limit'],
            'sort_order' => 0,
        ]);
        $next = app(TrafficResetService::class)->calculateNextResetTimeForPlan($plan, $expiredAt);
        $row->next_reset_at = $next?->timestamp;
        $row->save();
    }

    /**
     * 暂存「指定套餐开通」意图（不写库）。
     */
    private function prepareInitialPlan(User $user, int $planId, ?int $expiredAt = null): void
    {
        $plan = Plan::find($planId);
        if (!$plan) {
            return;
        }

        $user->draftInitialPlan = [
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
            'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
            'expired_at' => $expiredAt ?: null,
        ];
    }

    /**
     * 暂存「试用套餐开通」意图（不写库）。
     */
    private function prepareTryOutPlan(User $user): void
    {
        $planId = (int) admin_setting('try_out_plan_id', 0);
        if (!$planId) {
            return;
        }
        $plan = Plan::find($planId);
        if (!$plan) {
            return;
        }

        $user->draftInitialPlan = [
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => null,
            'transfer_enable' => (int) $plan->transfer_enable * 1073741824,
            'expired_at' => time() + ((int) admin_setting('try_out_hour', 1) * 3600),
        ];
    }
}
