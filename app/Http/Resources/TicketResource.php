<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 如果ID已经是withdrawal_前缀，保持不变
        $id = $this['id'];
        if (str_starts_with($id, 'withdrawal_')) {
            // 提现单，使用原始ID
        } else {
            // 工单，添加ticket_前缀
            $id = 'ticket_' . $this['id'];
        }

        $data = [
            "id" => $id,
            "level" => $this['level'],
            "reply_status" => $this['reply_status'],
            "status" => $this['status'],
            "subject" => $this['subject'],
            "message" => array_key_exists('message', $this->additional) ? MessageResource::collection($this['message']) : null,
            "created_at" => $this['created_at'],
            "updated_at" => $this['updated_at']
        ];
        if (!config('hidden_features.enable_exposed_user_count_fix')) $data['user_id'] = $this['user_id'];
        return $data;
    }
}
