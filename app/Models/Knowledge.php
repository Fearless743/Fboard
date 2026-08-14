<?php

namespace App\Models;

use App\Traits\HasPinyinSearch;
use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    use HasPinyinSearch;

    protected $table = 'v2_knowledge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'show' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * 需要生成拼音索引的字段
     */
    protected array $pinyinSearchable = ['title', 'category'];
}
