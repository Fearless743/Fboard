<?php

namespace App\Models;

use App\Traits\HasPinyinSearch;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasPinyinSearch;

    protected $table = 'v2_payment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'config' => 'array',
        'enable' => 'boolean'
    ];

    protected $hidden = [
        'config',
    ];

    /**
     * 需要生成拼音索引的字段
     */
    protected array $pinyinSearchable = ['name'];
}
