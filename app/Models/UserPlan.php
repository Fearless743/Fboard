<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\UserPlan
 *
 * 用户套餐实例：一个用户可同时持有多个套餐，每个实例独立计量流量与到期时间。
 *
 * @property int $id
 * @property int $user_id 用户ID
 * @property int $plan_id 套餐ID
 * @property int|null $order_id 最近一笔开通/续费订单ID
 * @property int|null $group_id 权限组ID（冗余自 plan.group_id）
 * @property int $transfer_enable 流量配额（字节）
 * @property int $u 已用上行流量（字节）
 * @property int $d 已用下行流量（字节）
 * @property int|null $expired_at 到期时间（NULL=一次性流量包）
 * @property int|null $next_reset_at 下次流量重置时间
 * @property int|null $last_reset_at 上次流量重置时间
 * @property int $reset_count 流量重置次数
 * @property string $source 来源：order|admin|tryout|migrate
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read User|null $user
 * @property-read Plan|null $plan
 * @property-read Order|null $order
 */
class UserPlan extends Model
{
    public const SOURCE_ORDER = 'order';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_TRYOUT = 'tryout';
    public const SOURCE_MIGRATE = 'migrate';

    protected $table = 'v2_user_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expired_at' => 'timestamp',
        'next_reset_at' => 'timestamp',
        'last_reset_at' => 'timestamp',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'reset_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 该实例是否未过期（一次性流量包视为长期有效）
     */
    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at <= time();
    }

    /**
     * 该实例是否流量充足
     */
    public function hasRemainingTraffic(): bool
    {
        return ($this->u + $this->d) < $this->transfer_enable;
    }

    /**
     * 该实例是否处于活跃状态（未过期且流量未耗尽）
     */
    public function isActive(): bool
    {
        return !$this->isExpired() && $this->hasRemainingTraffic();
    }

    /**
     * 剩余流量（字节）
     */
    public function getRemainingTraffic(): int
    {
        return max(0, $this->transfer_enable - ($this->u + $this->d));
    }
}
