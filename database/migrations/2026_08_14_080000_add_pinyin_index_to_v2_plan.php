<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_plan')) {
            return;
        }
        if (Schema::hasColumn('v2_plan', 'pinyin_index')) {
            return;
        }
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->text('pinyin_index')
                ->nullable()
                ->after('updated_at')
                ->comment('拼音搜索索引（全拼 + 首字母缩写，由 HasPinyinSearch trait 自动维护）');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_plan') || !Schema::hasColumn('v2_plan', 'pinyin_index')) {
            return;
        }
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn('pinyin_index');
        });
    }
};