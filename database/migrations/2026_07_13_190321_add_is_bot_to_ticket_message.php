<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->after('message')
                ->comment('是否为机器人自动回复');
        });
    }

    public function down(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->dropColumn('is_bot');
        });
    }
};
