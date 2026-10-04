<?php

namespace App\Enums;

/**
 * 套餐实例类型（v2_user_plan.kind）
 *
 * - Cycle：周期套餐，同一 (user, plan) 只保留一行，续费/过期重买复用该行
 * - Pack：流量包，每次购买独立成行，用完即止
 */
enum UserPlanKind: int
{
    case Cycle = 1;
    case Pack = 2;
}
