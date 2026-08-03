<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('withdrawal_id')->comment('提现单ID');
            $table->unsignedInteger('user_id')->comment('发送者ID（管理员或客服）');
            $table->text('message')->comment('消息内容');
            $table->unsignedTinyInteger('is_admin')->default(0)->comment('是否管理员发送');
            $table->timestamps();
            $table->index('withdrawal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_messages');
    }
};
