<?php

namespace App\Models;

use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\User
 *
 * @property int $id 用户ID
 * @property string $email 邮箱
 * @property string $password 密码
 * @property string|null $password_algo 加密方式
 * @property string|null $password_salt 加密盐
 * @property string $token 邀请码
 * @property string $uuid
 * @property int|null $invite_user_id 邀请人
 * @property int|null $plan_id 订阅ID
 * @property int|null $group_id 权限组ID
 * @property int|null $transfer_enable 流量(KB)
 * @property int|null $speed_limit 限速Mbps
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $banned 是否封禁
 * @property int|null $remind_expire 到期提醒
 * @property int|null $remind_traffic 流量提醒
 * @property int|null $expired_at 过期时间
 * @property int|null $balance 余额
 * @property int|null $commission_balance 佣金余额
 * @property float $commission_rate 返佣比例
 * @property int|null $commission_type 返佣类型
 * @property int|null $device_limit 设备限制数量
 * @property int|null $discount 折扣
 * @property int|null $last_login_at 最后登录时间
 * @property string|null $last_login_ip 最后登录 IP
 * @property string|null $register_ip 注册 IP
 * @property int|null $parent_id 父账户ID
 * @property int|null $is_admin 是否管理员
 * @property int|null $next_reset_at 下次流量重置时间
 * @property int|null $last_reset_at 上次流量重置时间
 * @property int|null $telegram_id Telegram ID
 * @property int $reset_count 流量重置次数
 * @property int $created_at
 * @property int $updated_at
 * @property bool $commission_auto_check 是否自动计算佣金
 *
 * @property-read User|null $invite_user 邀请人信息
 * @property-read \App\Models\Plan|null $plan 用户订阅计划
 * @property-read ServerGroup|null $group 权限组
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InviteCode> $codes 邀请码列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders 订单列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatUser> $stat 统计信息
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Ticket> $tickets 工单列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TrafficResetLog> $trafficResetLogs 流量重置记录
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UserLoginLog> $loginLogs 登录历史
 * @property-read User|null $parent 父账户
 * @property-read string $subscribe_url 订阅链接（动态生成）
 */
class User extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'banned' => 'boolean',
        'is_admin' => 'boolean',
        'is_staff' => 'boolean',
        'remind_expire' => 'boolean',
        'remind_traffic' => 'boolean',
        'commission_auto_check' => 'boolean',
        'commission_rate' => 'float',
        'next_reset_at' => 'timestamp',
        'last_reset_at' => 'timestamp',
    ];
    protected $hidden = ['password'];

    public const COMMISSION_TYPE_SYSTEM = 0;
    public const COMMISSION_TYPE_PERIOD = 1;
    public const COMMISSION_TYPE_ONETIME = 2;
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower(trim($value)),
        );
    }

    /**
     * 按邮箱查询（大小写不敏感，兼容所有数据库）
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', strtolower(trim($email)));
    }

    // 获取邀请人信息
    public function invite_user(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invite_user_id', 'id');
    }

    /**
     * 获取用户订阅计划
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id', 'id');
    }

    /**
     * 名下全部套餐实例（多套餐）。
     * 注意：必须返回 HasMany（禁返回 Collection），调用方需要时再 ->get()。
     */
    public function userPlans(): HasMany
    {
        return $this->hasMany(UserPlan::class, 'user_id', 'id');
    }

    /**
     * 名下有效套餐实例（expired_at 为 null 或 > now），保持链式。
     */
    public function activeUserPlans(?int $now = null): HasMany
    {
        $relation = $this->userPlans();
        UserPlan::applyActive($relation->getQuery(), $now);

        return $relation;
    }

    /**
     * 列表/CSV 复用入口：一次查询带出实例聚合列（withSum/withMax/withCount）。
     * 分组并集 group_ids 不在此列（GROUP_CONCAT 方言不兼容），列表页另用
     * with('userPlans') 或批量 pluck，一律经 normalizePlanAggregate() 归一。
     */
    public function scopeWithPlanAggregate(Builder $query, ?int $now = null): Builder
    {
        $now ??= time();
        $active = fn (Builder $q): Builder => UserPlan::applyActive($q, $now);

        return $query
            ->withSum(['userPlans as plans_quota' => $active], 'transfer_enable')
            ->withSum(['userPlans as plans_u' => $active], 'u')
            ->withSum(['userPlans as plans_d' => $active], 'd')
            ->withMax(['userPlans as plans_expired_max' => $active], 'expired_at')
            ->withMax(['userPlans as plans_speed_max' => $active], 'speed_limit')
            ->withMax(['userPlans as plans_device_max' => $active], 'device_limit')
            ->withCount(['userPlans as plans_active_count' => $active])
            ->withCount(['userPlans as plans_permanent_count' => fn (Builder $q): Builder => UserPlan::applyActive($q, $now)->whereNull('expired_at')]);
    }

    /**
     * SQL 内判定复用入口：持有任一有效实例的用户。
     */
    public function scopeWherePlanActive(Builder $query, ?int $now = null): Builder
    {
        $now ??= time();
        $table = $query->getModel()->getTable();

        return $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('v2_user_plan')
            ->whereColumn('v2_user_plan.user_id', "{$table}.id")
            ->where(fn ($w) => $w->whereNull('expired_at')->orWhere('expired_at', '>', $now)));
    }

    /**
     * SQL 内判定复用入口：有效实例已耗尽的用户（SUM(u+d) >= SUM(transfer_enable)）。
     * 注意正向写法：耗尽 = 用量之和达到配额之和，禁写反。
     */
    public function scopeWherePlanExhausted(Builder $query, ?int $now = null): Builder
    {
        $now ??= time();
        $table = $query->getModel()->getTable();
        $valid = '(expired_at IS NULL OR expired_at > ?)';
        $sum = fn (string $expr) => "(SELECT COALESCE(SUM({$expr}), 0) FROM v2_user_plan"
            . " WHERE v2_user_plan.user_id = {$table}.id AND {$valid})";

        // 无实例用户两边都是 0（0>=0 为真），必须先守卫“持有有效实例”。
        return $query->wherePlanActive($now)
            ->whereRaw("{$sum('u + d')} >= {$sum('transfer_enable')}", [$now, $now]);
    }

    public function hasActiveUserPlan(?int $now = null): bool
    {
        return $this->activeUserPlans($now)->exists();
    }

    /**
     * 单用户聚合复用入口（内存求和）。
     * 聚合语义（实时计算）：有效实例=未到期；配额/用量=有效实例之和；
     * 到期=任一永久则 null 否则最晚；限速/设备=有效值 max；分组=有效实例 group_id 去重并集。
     *
     * @return array{is_active:bool,quota:int,used:int,remaining:int,expired_at:?int,speed_limit:?int,device_limit:?int,group_ids:int[],active_count:int}
     */
    public function getPlanAggregate(?int $now = null): array
    {
        $now ??= time();

        if ($this->userPlansLoaded()) {
            /** @var \Illuminate\Support\Collection<int, UserPlan> $plans */
            $plans = $this->getRelation('userPlans')->filter(fn (UserPlan $p) => $p->isActive($now))->values();
            $groupIds = $plans->map(fn (UserPlan $p) => (int) $p->group_id)->unique()->sort()->values()->all();

            return self::normalizePlanAggregate(
                $plans->sum(fn (UserPlan $p) => (int) $p->transfer_enable),
                $plans->sum(fn (UserPlan $p) => (int) $p->u + (int) $p->d),
                $plans->count(),
                $plans->contains(fn (UserPlan $p) => $p->expired_at === null),
                $plans->max(fn (UserPlan $p) => $p->expired_at !== null ? (int) $p->expired_at : null),
                $plans->max(fn (UserPlan $p) => $p->speed_limit !== null ? (int) $p->speed_limit : null),
                $plans->max(fn (UserPlan $p) => $p->device_limit !== null ? (int) $p->device_limit : null),
                $groupIds
            );
        }

        if ($this->hasEagerPlanAggregate()) {
            return self::normalizePlanAggregate(
                (int) $this->getAttribute('plans_quota'),
                (int) $this->getAttribute('plans_u') + (int) $this->getAttribute('plans_d'),
                (int) $this->getAttribute('plans_active_count'),
                (int) $this->getAttribute('plans_permanent_count') > 0,
                $this->getAttribute('plans_expired_max') !== null ? (int) $this->getAttribute('plans_expired_max') : null,
                $this->getAttribute('plans_speed_max') !== null ? (int) $this->getAttribute('plans_speed_max') : null,
                $this->getAttribute('plans_device_max') !== null ? (int) $this->getAttribute('plans_device_max') : null,
                $this->activeUserPlans($now)->pluck('group_id')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all()
            );
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, UserPlan> $instances */
        $instances = $this->activeUserPlans($now)->get();

        return self::normalizePlanAggregate(
            (int) $instances->sum('transfer_enable'),
            (int) $instances->sum('u') + (int) $instances->sum('d'),
            $instances->count(),
            $instances->contains(fn (UserPlan $p) => $p->expired_at === null),
            $instances->max('expired_at') !== null ? (int) $instances->max('expired_at') : null,
            $instances->max('speed_limit') !== null ? (int) $instances->max('speed_limit') : null,
            $instances->max('device_limit') !== null ? (int) $instances->max('device_limit') : null,
            $instances->pluck('group_id')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all()
        );
    }

    private function userPlansLoaded(): bool
    {
        return $this->relationLoaded('userPlans');
    }

    private function hasEagerPlanAggregate(): bool
    {
        return array_key_exists('plans_active_count', $this->attributes)
            && array_key_exists('plans_quota', $this->attributes);
    }

    /**
     * 三个复用入口的唯一归一出口：禁各写各的聚合 SQL/求和。
     */
    public static function normalizePlanAggregate(
        int $quota,
        int $used,
        int $activeCount,
        bool $hasPermanent,
        ?int $maxExpiredAt,
        ?int $maxSpeedLimit,
        ?int $maxDeviceLimit,
        array $groupIds
    ): array {
        return [
            'is_active' => $activeCount > 0,
            'quota' => $quota,
            'used' => $used,
            'remaining' => max(0, $quota - $used),
            'expired_at' => $activeCount === 0 ? null : ($hasPermanent ? null : $maxExpiredAt),
            'speed_limit' => $maxSpeedLimit,
            'device_limit' => $maxDeviceLimit,
            'group_ids' => array_values(array_map('intval', $groupIds)),
            'active_count' => $activeCount,
        ];
    }

    // 获取用户邀请码列表
    public function codes(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'user_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function stat(): HasMany
    {
        return $this->hasMany(StatUser::class, 'user_id', 'id');
    }

    // 关联工单列表
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'user_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 关联流量重置记录
     */
    public function trafficResetLogs(): HasMany
    {
        return $this->hasMany(TrafficResetLog::class, 'user_id', 'id');
    }

    /**
     * 关联登录历史
     */
    public function loginLogs(): HasMany
    {
        return $this->hasMany(UserLoginLog::class, 'user_id', 'id');
    }

    /**
     * 检查用户是否处于活跃状态
     */
    public function isActive(): bool
    {
        return !$this->banned && 
               ($this->expired_at === null || $this->expired_at > time()) &&
               $this->plan_id !== null;
    }

    /** 
     * 检查用户是否可用节点流量且充足
     */
    public function isAvailable(): bool
    {     
        return $this->isActive() && $this->getRemainingTraffic() > 0;   
    }

    /**
     * 检查是否需要重置流量
     */
    public function shouldResetTraffic(): bool
    {
        return $this->isActive() &&
               $this->next_reset_at !== null &&
               $this->next_reset_at <= time();
    }

    /**
     * 获取总使用流量
     */
    public function getTotalUsedTraffic(): int
    {
        return ($this->u ?? 0) + ($this->d ?? 0);
    }

    /**
     * 获取剩余流量
     */
    public function getRemainingTraffic(): int
    {
        $used = $this->getTotalUsedTraffic();
        $total = $this->transfer_enable ?? 0;
        return max(0, $total - $used);
    }

    /**
     * 获取流量使用百分比
     */
    public function getTrafficUsagePercentage(): float
    {
        $total = $this->transfer_enable ?? 0;
        if ($total <= 0) {
            return 0;
        }
        
        $used = $this->getTotalUsedTraffic();
        return min(100, ($used / $total) * 100);
    }
}
