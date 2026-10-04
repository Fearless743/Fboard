<?php

namespace App\Models;

use App\Enums\UserPlanKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\UserPlan
 *
 * 用户持有的单个套餐实例（多套餐唯一写路径，见 multi-plan 方案 §三）。
 * 一行性靠流程三铁律保证，本表不设业务唯一键。
 *
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property UserPlanKind $kind 1=周期 2=流量包
 * @property int $group_id 购买时从 plan 快照，plan 改组不影响已购
 * @property array $order_ids 全部关联订单 id
 * @property int $transfer_enable 本行累计配额（字节）
 * @property int $u 本行已用上行
 * @property int $d 本行已用下行
 * @property int|null $expired_at null=永久
 * @property int|null $exhausted_at 首次耗尽时间，只写一次
 * @property int|null $next_reset_at 仅 cycle 行用
 * @property int|null $speed_limit null=跟随 plan
 * @property int|null $device_limit null=跟随 plan
 * @property int $sort_order 0=未设置走默认规则，越小越先扣
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read User $user
 * @property-read Plan $plan
 * @property-read ServerGroup|null $group
 */
class UserPlan extends Model
{
    protected $table = 'v2_user_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const KIND_CYCLE = 1;
    public const KIND_PACK = 2;

    /**
     * 实例表总开关已移除：实例表是唯一数据源，无条件启用。
     * 保留方法供旧调用兼容，一律返回 true。
     */
    public static function isEnabled(): bool
    {
        return true;
    }

    protected $attributes = [
        'order_ids' => '[]',
        'transfer_enable' => 0,
        'u' => 0,
        'd' => 0,
        'sort_order' => 0,
    ];

    protected $casts = [
        'kind' => UserPlanKind::class,
        'order_ids' => 'array',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'expired_at' => 'integer',
        'exhausted_at' => 'integer',
        'next_reset_at' => 'integer',
        'speed_limit' => 'integer',
        'device_limit' => 'integer',
        'sort_order' => 'integer',
        // 注意：时间三列刻意不用 timestamp cast，保持原生 int，
        // 接口层直接输出 int（禁 ?->timestamp），聚合比较也无需 Carbon。
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id', 'id');
    }

    /**
     * 有效实例：未到期（null=永久）即有效。
     */
    public function scopeActive(Builder $query, ?int $now = null): Builder
    {
        return self::applyActive($query, $now);
    }

    /**
     * 有效实例条件的唯一定义处（scope/聚合入口/SQL 入口共用，禁各写各的 SQL）。
     * 注意：$query 必须是 UserPlan 的 Eloquent 查询（withSum/withCount 的约束回调
     * 传进来的正是它），不可传入底层 Query Builder。
     */
    public static function applyActive(Builder $query, ?int $now = null): Builder
    {
        $now ??= time();

        return $query->where(fn (Builder $q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', $now));
    }

    /**
     * 流量扣减顺序：sort_order ASC（0 沉底）→ expired_at ASC（永久沉底）→ created_at ASC。
     */
    public function scopeOrderedForDeduction(Builder $query): Builder
    {
        return self::applyDeductionOrder($query);
    }

    /**
     * 扣减排序的唯一定义处（Job 内批量预加载与单用户查询共用）。
     */
    public static function applyDeductionOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN sort_order = 0 THEN 1 ELSE 0 END ASC, sort_order ASC')
            ->orderByRaw('CASE WHEN expired_at IS NULL THEN 1 ELSE 0 END ASC, expired_at ASC')
            ->orderBy('created_at', 'asc');
    }

    public function isActive(?int $now = null): bool
    {
        return $this->expired_at === null || $this->expired_at > ($now ?? time());
    }

    public function isExhausted(): bool
    {
        return ($this->u ?? 0) + ($this->d ?? 0) >= ($this->transfer_enable ?? 0);
    }

    public function getRemainingTraffic(): int
    {
        return max(0, ($this->transfer_enable ?? 0) - ($this->u ?? 0) - ($this->d ?? 0));
    }

    /**
     * 追加关联订单 id（去重）。
     */
    public function appendOrderId(int $orderId): void
    {
        $ids = $this->order_ids ?? [];
        if (! in_array($orderId, $ids, true)) {
            $ids[] = $orderId;
            $this->order_ids = $ids;
        }
    }
}
