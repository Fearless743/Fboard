<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserPlan extends Model
{
    protected $table = 'v2_user_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expired_at' => 'timestamp',
    ];

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联套餐
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /**
     * 关联订单（记录来源）
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'id', 'order_id');
    }

    /**
     * 检查该套餐是否仍然有效（未过期）
     */
    public function isActive(): bool
    {
        return $this->expired_at === null || $this->expired_at > time();
    }

    /**
     * Scope: 只返回有效的套餐关联
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expired_at')
              ->orWhere('expired_at', '>', time());
        });
    }
}
