<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'type' => $this['type'],
            'version' => $this['version'] ?? null,
            'name' => $this['name'],
            'rate' => $this['rate'],
            'tags' => $this['tags'] ?? [],
            // 虚拟节点合并后若未 re-append，避免 Undefined array key 打爆用户节点列表
            'is_online' => $this['is_online'] ?? 0,
            'cache_key' => $this['cache_key'] ?? null,
            'last_check_at' => $this['last_check_at'] ?? null,
        ];
    }
}
