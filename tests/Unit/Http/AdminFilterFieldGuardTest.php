<?php

namespace Tests\Unit\Http;

use App\Traits\QueryOperators;
use Tests\TestCase;

/**
 * 管理端 filter/sort 字段必须是纯标识符（可含单点关联），拒绝表达式注入。
 */
class AdminFilterFieldGuardTest extends TestCase
{
    public function test_accepts_identifier_fields(): void
    {
        $guard = $this->guard();

        foreach (['id', 'total_used', 'created_at', 'user.email', 'commission_status'] as $field) {
            $this->assertTrue($guard->isSafe($field), "should accept: {$field}");
        }
    }

    public function test_rejects_expression_fields(): void
    {
        $guard = $this->guard();

        $malicious = [
            'id) OR 1=1 --',
            'id, password',
            '(select 1)',
            'id/**/or/**/1=1',
            'a b',
            'id; drop table v2_user',
            'a..b',
            'a.b.c',
            'id`',
        ];

        foreach ($malicious as $field) {
            $this->assertFalse($guard->isSafe($field), "should reject: {$field}");
        }
    }

    private function guard(): object
    {
        return new class {
            use QueryOperators;

            public function isSafe(string $field): bool
            {
                return $this->isSafeQueryField($field);
            }
        };
    }
}
