<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'register_ip')) {
                $table->string('register_ip', 45)->nullable()->after('last_login_ip')->comment('注册 IP');
            }
        });

        // last_login_ip 原为 integer，无法存 IPv6；历史代码从未写入，直接改为 string(45)
        if (Schema::hasColumn('v2_user', 'last_login_ip')) {
            // 先清空可能存在的整型脏数据，再改类型
            DB::table('v2_user')->update(['last_login_ip' => null]);

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `v2_user` MODIFY `last_login_ip` VARCHAR(45) NULL');
            } else {
                // SQLite 等：重建列
                Schema::table('v2_user', function (Blueprint $table) {
                    $table->dropColumn('last_login_ip');
                });
                Schema::table('v2_user', function (Blueprint $table) {
                    $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
                });
            }
        }

        if (!Schema::hasTable('v2_user_login_log')) {
            Schema::create('v2_user_login_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('用户 ID');
                $table->string('ip', 45)->comment('登录 IP');
                $table->string('user_agent', 512)->nullable()->comment('User-Agent');
                $table->string('method', 32)->comment('登录方式: password/register/mail_link');
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index('user_id', 'idx_user_login_log_user_id');
                $table->index(['user_id', 'created_at'], 'idx_user_login_log_user_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_login_log');

        if (Schema::hasColumn('v2_user', 'register_ip')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('register_ip');
            });
        }

        if (Schema::hasColumn('v2_user', 'last_login_ip')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `v2_user` MODIFY `last_login_ip` INT NULL');
            } else {
                Schema::table('v2_user', function (Blueprint $table) {
                    $table->dropColumn('last_login_ip');
                });
                Schema::table('v2_user', function (Blueprint $table) {
                    $table->integer('last_login_ip')->nullable()->after('last_login_at');
                });
            }
        }
    }
};
