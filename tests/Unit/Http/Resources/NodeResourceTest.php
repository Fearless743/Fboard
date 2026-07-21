<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\NodeResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class NodeResourceTest extends TestCase
{
    public function test_missing_optional_keys_do_not_throw(): void
    {
        $resource = new NodeResource([
            'id' => 1,
            'type' => 'vless',
            'name' => 'test-node',
            'rate' => 1.0,
            // 故意不提供 is_online / cache_key / last_check_at / tags
            // 模拟虚拟节点合并后 toArray 丢字段的生产故障场景
        ]);

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame(1, $array['id']);
        $this->assertSame('vless', $array['type']);
        $this->assertSame(0, $array['is_online']);
        $this->assertNull($array['cache_key']);
        $this->assertNull($array['last_check_at']);
        $this->assertSame([], $array['tags']);
    }

    public function test_present_is_online_is_preserved(): void
    {
        $resource = new NodeResource([
            'id' => 2,
            'type' => 'trojan',
            'name' => 'online-node',
            'rate' => 1.5,
            'tags' => ['hk'],
            'is_online' => 1,
            'cache_key' => 'trojan-2-1-1',
            'last_check_at' => 1710000000,
        ]);

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame(1, $array['is_online']);
        $this->assertSame('trojan-2-1-1', $array['cache_key']);
        $this->assertSame(1710000000, $array['last_check_at']);
        $this->assertSame(['hk'], $array['tags']);
    }
}
