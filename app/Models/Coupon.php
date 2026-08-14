<?php

namespace App\Models;

use App\Services\PlanService;
use App\Traits\HasPinyinSearch;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasPinyinSearch;

    protected $table = 'v2_coupon';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'limit_plan_ids' => 'array',
        'limit_period' => 'array',
        'show' => 'boolean',
    ];

    /**
     * 需要生成拼音索引的字段
     */
    protected array $pinyinSearchable = ['name'];

    public function getLimitPeriodAttribute($value)
    {
        return collect(json_decode((string) $value, true))->map(function ($item) {
            return PlanService::getPeriodKey($item);
        })->toArray();
    }

}
