<?php

namespace App\Traits;

use Illuminate\Contracts\Database\Query\Expression;

trait QueryOperators
{
    /**
     * 过滤/排序列名合法性：仅允许字母数字下划线，关联字段用单个点分隔。
     * 阻止把表达式、函数、括号等注入 where/orderBy 的列位置（列名会被直接用于
     * 查询构造器，虽经 Laravel 包裹转义，仍应限制取值范围）。
     */
    protected function isSafeQueryField(string $field): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)?$/', $field);
    }

    /**
     * 获取查询运算符映射
     *
     * @param string $operator
     * @return string
     */
    protected function getQueryOperator(string $operator): string
    {
        return match (strtolower($operator)) {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'notlike' => 'not like',
            'null' => 'null',
            'notnull' => 'notnull',
            default => 'like'
        };
    }

    /**
     * 获取查询值格式化
     *
     * @param string $operator
     * @param mixed $value
     * @return mixed
     */
    protected function formatQueryValue(string $operator, mixed $value): mixed
    {
        return match (strtolower($operator)) {
            'like', 'notlike' => "%{$value}%",
            'null', 'notnull' => null,
            default => $value
        };
    }

    /**
     * 应用查询条件
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $field
     * @param string $operator
     * @param mixed $value
     * @return void
     */
    protected function applyQueryCondition($query, array|Expression|string $field, string $operator, mixed $value): void
    {
        $queryOperator = $this->getQueryOperator($operator);
        
        if ($queryOperator === 'null') {
            $query->whereNull($field);
        } elseif ($queryOperator === 'notnull') {
            $query->whereNotNull($field);
        } else {
            $query->where($field, $queryOperator, $this->formatQueryValue($operator, $value));
        }
    }
} 