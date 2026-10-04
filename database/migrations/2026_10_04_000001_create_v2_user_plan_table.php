<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 多套餐 PR1：创建实例表 v2_user_plan（16 列），并把存量单套餐用户迁入。
 *
 * 说明：
 * - created_at/updated_at 沿用项目惯例用 integer（配合 Model dateFormat=U），
 *   而非 timestamp 类型；读写语义与 v2_user/v2_plan 一致。
 * - order_ids 为 json NOT NULL；MySQL json 列不支持字面默认值，
 *   空数组默认值由 UserPlan::$attributes 在模型层保证，写入时必须显式带值。
 * - 本表不设业务唯一键，一行性靠订单流程三铁律 + 定时一致性检查保证。
 * - 数据迁移与 artisan 命令共用 UserPlanMigrator 实现，可重跑可中断
 *   （同 user+plan 已有 cycle 行跳过）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_user_plan', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->unsignedTinyInteger('kind')->comment('1=周期套餐 2=流量包');
            $table->unsignedBigInteger('group_id')->comment('购买时从 plan 快照');
            $table->json('order_ids')->comment('全部关联订单 id');
            $table->bigInteger('transfer_enable')->default(0)->comment('本行累计配额（字节）');
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->unsignedBigInteger('expired_at')->nullable()->comment('null=永久');
            $table->unsignedBigInteger('exhausted_at')->nullable()->comment('首次耗尽时间，只写一次');
            $table->unsignedBigInteger('next_reset_at')->nullable()->comment('仅 cycle 行用');
            $table->unsignedInteger('speed_limit')->nullable()->comment('null=跟随 plan');
            $table->unsignedInteger('device_limit')->nullable()->comment('null=跟随 plan');
            $table->integer('sort_order')->default(0)->comment('0=未设置走默认规则，越小越先扣');
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        // 存量单套餐用户迁入实例表（幂等：已有 cycle 行跳过）。
        \App\Services\UserPlanMigrator::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_plan');
    }
};
