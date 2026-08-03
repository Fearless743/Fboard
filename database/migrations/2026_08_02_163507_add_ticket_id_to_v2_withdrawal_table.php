<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_withdrawal', function (Blueprint $table) {
            $table->unsignedInteger('ticket_id')->nullable()->after('id');
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_withdrawal', function (Blueprint $table) {
            $table->dropIndex(['ticket_id']);
            $table->dropColumn('ticket_id');
        });
    }
};
