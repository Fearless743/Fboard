<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * usque/mihomo MASQUE 设备密钥（ECDSA P-256，base64 DER）。
     * private 供 mihomo 订阅（private-key），public 随节点用户同步下发
     *（节点用其校验 QUIC 客户端证书），缺失时自动生成回填。
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        // 幂等：容器启动时 migrate 可能被中断重跑，列已存在时不再重复添加。
        if (!Schema::hasColumn('v2_user', 'usque_device_private')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->text('usque_device_private')->nullable()
                    ->comment('usque 设备私钥 base64(SEC1 DER)');
            });
        }
        if (!Schema::hasColumn('v2_user', 'usque_device_public')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->text('usque_device_public')->nullable()->after('usque_device_private')
                    ->comment('usque 设备公钥 base64(PKIX DER)');
            });
        }

        // 注意：不在此回填全表密钥。PasswordGenerators::masque() 缺失时
        // 自愈生成并持久化（User::creating 覆盖新用户），逐用户
        // openssl_pkey_new + 逐行 UPDATE 在大库上会导致容器启动时
        // migrate 卡死（每次重启从头再来）。保持列可空即可。
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }
        Schema::table('v2_user', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('v2_user', 'usque_device_private')) {
                $columns[] = 'usque_device_private';
            }
            if (Schema::hasColumn('v2_user', 'usque_device_public')) {
                $columns[] = 'usque_device_public';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
