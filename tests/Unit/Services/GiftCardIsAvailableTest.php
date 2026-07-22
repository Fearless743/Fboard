<?php

namespace Tests\Unit\Services;

use App\Models\GiftCardCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STATUS_USED 的码必须不可再兑；仅查 EXPIRED/DISABLED 会漏掉「已标记 USED 但 usage_count 被改」等异常行。
 */
class GiftCardIsAvailableTest extends TestCase
{
    use RefreshDatabase;

    public function test_used_status_is_not_available_even_if_usage_count_zero(): void
    {
        $code = new GiftCardCode();
        $code->forceFill([
            'template_id' => 1,
            'code' => 'GCTESTUSED0001',
            'status' => GiftCardCode::STATUS_USED,
            'usage_count' => 0,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        // 不 save 到 DB 也可测 isAvailable 纯逻辑
        $this->assertFalse(
            $code->isAvailable(),
            'STATUS_USED 必须视为不可用'
        );
    }
}
