<?php

namespace App\Models;

use App\Traits\HasPinyinSearch;
use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    use HasPinyinSearch;

    protected $table = 'v2_notice';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'tags' => 'array',
        'show' => 'boolean',
    ];

    /**
     * 需要生成拼音索引的字段（对应搜索结果）
     */
    protected array $pinyinSearchable = ['title', 'tags'];
}
