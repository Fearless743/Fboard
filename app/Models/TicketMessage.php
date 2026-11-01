<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\TicketMessage
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $message
 * @property bool $is_bot 是否为机器人自动回复
 * @property int|null $recalled_at 撤回时间
 * @property int|null $edited_at 最后编辑时间
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Ticket $ticket 关联的工单
 * @property-read bool $is_from_user 消息是否由工单发起人发送
 * @property-read bool $is_from_admin 消息是否由管理员发送
 * @property-read bool $is_from_bot 消息是否为机器人回复
 * @property-read bool $is_recalled 消息是否已撤回
 * @property-read bool $is_edited 消息是否被编辑过
 */
class TicketMessage extends Model
{
    protected $table = 'v2_ticket_message';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'recalled_at' => 'timestamp',
        'edited_at' => 'timestamp',
        'is_bot' => 'boolean',
    ];

    protected $appends = ['is_from_user', 'is_from_admin', 'is_from_bot', 'is_recalled', 'is_edited'];
    protected $hidden = ['ticket'];

    /**
     * 关联的工单
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    /**
     * 判断消息是否由工单发起人发送
     */
    public function getIsFromUserAttribute(): bool
    {
        return $this->ticket && $this->ticket->user_id === $this->user_id;
    }

    /**
     * 判断消息是否由管理员发送
     */
    public function getIsFromAdminAttribute(): bool
    {
        return $this->ticket && $this->ticket->user_id !== $this->user_id;
    }

    /**
     * 判断消息是否为机器人自动回复
     */
    public function getIsFromBotAttribute(): bool
    {
        return (bool) $this->is_bot;
    }

    /**
     * 判断消息是否已撤回
     */
    public function getIsRecalledAttribute(): bool
    {
        return $this->recalled_at !== null;
    }

    /**
     * 判断消息是否被编辑过
     */
    public function getIsEditedAttribute(): bool
    {
        return $this->edited_at !== null;
    }
}
