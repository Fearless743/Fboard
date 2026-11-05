<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户登录历史
 *
 * @property int $id
 * @property int $user_id
 * @property string $ip
 * @property string|null $user_agent
 * @property string $method password|register|mail_link
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read User $user
 */
class UserLoginLog extends Model
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_REGISTER = 'register';
    public const METHOD_MAIL_LINK = 'mail_link';

    /** 每用户保留的最大历史条数 */
    public const MAX_PER_USER = 20;

    protected $table = 'v2_user_login_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
