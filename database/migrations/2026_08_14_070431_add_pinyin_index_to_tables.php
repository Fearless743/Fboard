<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 9 张支持搜索的表添加 pinyin_index TEXT 列（可空）。
     * 该列由 HasPinyinSearch trait 自动维护，存储中文全拼+首字母缩写，
     * 用于实现拼音模糊搜索。
     */
    private array $tables = [
        'v2_notice',
        'v2_knowledge',
        'v2_coupon',
        'v2_payment',
        'v2_server',
        'v2_server_machine',
        'v2_cert_templates',
        'v2_gift_card_template',
        'v2_ticket',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'pinyin_index')) {
                continue;
            }
            Schema::table($table, function (Blueprint $table) {
                $table->text('pinyin_index')
                    ->nullable()
                    ->after('updated_at')
                    ->comment('拼音搜索索引（全拼 + 首字母缩写，由 HasPinyinSearch trait 自动维护）');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'pinyin_index')) {
                continue;
            }
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('pinyin_index');
            });
        }
    }
};