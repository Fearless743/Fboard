<?php

namespace App\Observers;

use App\Models\Plan;
use App\Models\UserPlan;
use App\Services\TrafficResetService;

/**
 * 套餐 reset_traffic_method 变更后，重算所有持有该套餐实例的 cycle 行 next_reset_at。
 */
class PlanObserver
{
    public function updated(Plan $plan): void
    {
        if (!$plan->isDirty('reset_traffic_method')) {
            return;
        }
        $trafficResetService = app(TrafficResetService::class);
        UserPlan::where('plan_id', $plan->id)
            ->where('kind', UserPlan::KIND_CYCLE)
            ->lazyById(500)
            ->each(function (UserPlan $row) use ($trafficResetService, $plan) {
                $nextResetTime = $trafficResetService->calculateNextResetTimeForPlan($plan, $row->expired_at);
                $row->update([
                    'next_reset_at' => $nextResetTime?->timestamp,
                ]);
            });
    }
}
