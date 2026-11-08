<?php

use App\Utils\Helper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        Schema::table('v2_user', function (Blueprint $table) {
            $table->text('usque_device_private')->nullable()->after('password')
                ->comment('usque 设备私钥 base64(SEC1 DER)');
            $table->text('usque_device_public')->nullable()->after('usque_device_private')
                ->comment('usque 设备公钥 base64(PKIX DER)');
        });

        DB::table('v2_user')
            ->whereNull('usque_device_private')
            ->orWhereNull('usque_device_public')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    if (!empty($user->usque_device_private) && !empty($user->usque_device_public)) {
                        continue;
                    }
                    try {
                        $kp = Helper::generateUsqueDeviceKeypair();
                    } catch (\Throwable $e) {
                        report($e);
                        continue;
                    }
                    DB::table('v2_user')->where('id', $user->id)->update([
                        'usque_device_private' => $kp['private'],
                        'usque_device_public' => $kp['public'],
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn(['usque_device_private', 'usque_device_public']);
        });
    }
};
