<?php

namespace App\Models;

use App\Traits\HasPinyinSearch;
use Illuminate\Database\Eloquent\Model;

class CertTemplate extends Model
{
    use HasPinyinSearch;

    protected $table = "v2_cert_templates";

    protected $guarded = ["id"];

    /**
     * 需要生成拼音索引的字段
     */
    protected array $pinyinSearchable = ["name", "description"];

    protected $casts = [
        "created_at" => "datetime",
        "updated_at" => "datetime",
    ];
}
