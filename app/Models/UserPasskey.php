<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理员 Passkey 凭证
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_id Base64URL
 * @property string $type
 * @property array|null $transports
 * @property string $attestation_type
 * @property string|null $aaguid
 * @property string $credential_public_key Base64 COSE 公钥
 * @property int $counter
 * @property bool $backup_eligible
 * @property bool $backup_status
 * @property int|null $last_used_at
 */
class UserPasskey extends Model
{
    protected $table = 'v2_user_passkeys';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'last_used_at' => 'timestamp',
        'transports' => 'array',
        'backup_eligible' => 'boolean',
        'backup_status' => 'boolean',
        'counter' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
