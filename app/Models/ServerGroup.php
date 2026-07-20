<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * App\Models\ServerGroup
 *
 * @property int $id
 * @property string $name 分组名
 * @property int $created_at
 * @property int $updated_at
 * @property-read int $server_count 服务器数量
 */
class ServerGroup extends Model
{
    protected $table = 'v2_server_group';
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'group_id', 'id');
    }

    public function servers()
    {
        // 同时匹配字符串和整型两种存储形式，避免 JSON_CONTAINS 类型不匹配
        return Server::where(function ($query) {
            $id = $this->id;
            $query->whereJsonContains('group_ids', (string) $id)
                ->orWhereJsonContains('group_ids', (int) $id);
        })->get();
    }

    /**
     * 获取服务器数量
     */
    protected function serverCount(): Attribute
    {
        return Attribute::make(
            get: function () {
                $id = $this->id;
                return Server::where(function ($query) use ($id) {
                    $query->whereJsonContains('group_ids', (string) $id)
                        ->orWhereJsonContains('group_ids', (int) $id);
                })->count();
            },
        );
    }
}
