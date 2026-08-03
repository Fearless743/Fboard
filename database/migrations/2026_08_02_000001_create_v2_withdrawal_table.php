<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_withdrawal', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->index();
            $table->string('withdraw_method');
            $table->string('withdraw_account');
            $table->integer('amount')->comment('单位：分');
            $table->integer('status')->default(0)->comment('0:待处理 1:已确认 2:已拒绝');
            $table->text('remark')->nullable();
            $table->integer('operator_id')->nullable()->comment('处理人用户ID');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_withdrawal');
    }
};
