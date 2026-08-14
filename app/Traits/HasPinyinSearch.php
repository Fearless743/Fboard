<?php

namespace App\Traits;

use App\Utils\PinyinHelper;
use Illuminate\Database\Eloquent\Builder;

/**
 * 为模型添加拼音搜索能力。
 *
 * 使用方式：
 *   1. 在模型中使用 `use HasPinyinSearch;`
 *   2. 定义 `protected array $pinyinSearchable = ['title', 'name'];`
 *   3. 确保数据库表有 `pinyin_index` TEXT 列（可空）
 *
 * 自动行为：
 *   - `saving` 事件：根据 `$pinyinSearchable` 字段自动生成 `pinyin_index`
 *   - `scopePinyinSearch`：同时匹配原文和拼音索引
 */
trait HasPinyinSearch
{
    /**
     * Boot the trait: 监听 saving 事件自动生成拼音索引。
     */
    protected static function bootHasPinyinSearch(): void
    {
        static::saving(function ($model) {
            /** @var self $model */
            $model->setPinyinIndex();
        });
    }

    /**
     * 根据当前模型的可搜索字段值，重新生成拼音索引。
     *
     * 统一用 $this->{$field}（属性访问器）取值：无论新建还是从数据库加载，
     * 都会经过 cast 解码。若误用 attributes 里的原始值，tags 等 array cast
     * 字段会拿到含 \uXXXX 转义的 JSON 字符串，导致拼音转错。
     */
    public function setPinyinIndex(): void
    {
        $parts = [];
        foreach ($this->pinyinSearchable ?? [] as $field) {
            $value = $this->{$field} ?? '';

            if (is_array($value)) {
                $value = implode(' ', $value);
            }
            $value = (string) $value;

            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $this->pinyin_index = PinyinHelper::toIndex(implode(' ', $parts));
    }

    /**
     * 拼音搜索 scope：同时匹配原文和拼音索引。
     *
     * @param Builder $query
     * @param string  $search       用户输入的搜索关键词
     * @param array   $fields       要搜索的原始字段列表（对应 $pinyinSearchable）
     * @param string  $pinyinColumn 拼音索引列名，默认 pinyin_index
     * @return void
     */
    public function scopePinyinSearch(Builder $query, string $search, array $fields, string $pinyinColumn = 'pinyin_index'): void
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $like = '%' . $escaped . '%';

        $query->where(function (Builder $q) use ($fields, $like, $pinyinColumn) {
            foreach ($fields as $field) {
                $q->orWhere($field, 'like', $like);
            }
            // 拼音索引匹配：用户输入的拼音会在此命中
            $q->orWhere($pinyinColumn, 'like', $like);
        });
    }
}