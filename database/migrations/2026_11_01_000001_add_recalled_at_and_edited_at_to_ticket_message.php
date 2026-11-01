<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->integer('recalled_at')->nullable()->after('is_bot')
                ->comment('撤回时间，非空表示消息已撤回');
            $table->integer('edited_at')->nullable()->after('recalled_at')
                ->comment('最后编辑时间');
        });
    }

    public function down(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->dropColumn(['recalled_at', 'edited_at']);
        });
    }
};
