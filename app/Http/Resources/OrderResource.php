<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isDeposit = OrderService::isDepositOrder($this->resource);

        return [
            ...parent::toArray($request),
            'period' => $isDeposit
                ? Order::PERIOD_DEPOSIT
                : PlanService::getLegacyPeriod((string) $this->period),
            'plan' => $isDeposit
                ? ['id' => 0, 'name' => 'deposit']
                : $this->whenLoaded('plan', fn () => $this->plan ? PlanResource::make($this->plan) : null),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }
}
