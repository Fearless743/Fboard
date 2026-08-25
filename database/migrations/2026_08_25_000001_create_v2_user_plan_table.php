<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('v2_user_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('order_id')->nullable(); // 来源订单
            $table->unsignedBigInteger('expired_at')->nullable(); // null 表示永久
            $table->unsignedInteger('speed_limit')->nullable(); // 继承自套餐的速度限制
            $table->timestamps();

            $table->index('user_id');
            $table->index('plan_id');
            $table->unique(['user_id', 'plan_id', 'order_id'], 'user_plan_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v2_user_plan');
    }
};
