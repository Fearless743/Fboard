<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2UserPlanTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('v2_user_plan')) {
            return;
        }

        Schema::create('v2_user_plan', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->comment('用户ID');
            $table->integer('plan_id')->comment('套餐ID');
            $table->integer('order_id')->nullable()->comment('最近一笔开通/续费订单ID');
            $table->integer('group_id')->nullable()->comment('权限组ID（冗余自 plan.group_id，供节点过滤）');
            $table->bigInteger('transfer_enable')->default(0)->comment('流量配额（字节）');
            $table->bigInteger('u')->default(0)->comment('已用上行流量（字节）');
            $table->bigInteger('d')->default(0)->comment('已用下行流量（字节）');
            $table->bigInteger('expired_at')->nullable()->comment('到期时间（NULL=一次性流量包）');
            $table->integer('next_reset_at')->nullable()->comment('下次流量重置时间');
            $table->integer('last_reset_at')->nullable()->comment('上次流量重置时间');
            $table->integer('reset_count')->default(0)->comment('流量重置次数');
            $table->string('source', 16)->default('order')->comment('来源：order|admin|tryout|migrate');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index('user_id', 'idx_up_user_id');
            $table->index('plan_id', 'idx_up_plan_id');
            $table->index('group_id', 'idx_up_group_id');
            $table->index('next_reset_at', 'idx_up_next_reset_at');
            $table->index(['user_id', 'plan_id', 'expired_at'], 'idx_up_user_plan_expired');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v2_user_plan');
    }
}
