<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Withdrawal extends Model
{
    protected $table = 'v2_withdrawal';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    const STATUS_PENDING = 0;
    const STATUS_CONFIRMED = 1;
    const STATUS_CLOSED = 2;

    public static $statusMap = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_CONFIRMED => '已确认',
        self::STATUS_CLOSED => '已拒绝',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id', 'id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WithdrawalMessage::class, 'withdrawal_id', 'id');
    }
}
