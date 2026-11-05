<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_user_passkeys')) {
            Schema::create('v2_user_passkeys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('用户 ID');
                $table->string('name', 64)->default('')->comment('备注名');
                $table->string('credential_id', 255)->unique()->comment('Base64URL 凭证 ID');
                $table->string('type', 32)->default('public-key');
                $table->text('transports')->nullable()->comment('JSON 数组');
                $table->string('attestation_type', 32)->default('none');
                $table->string('aaguid', 64)->nullable();
                $table->text('credential_public_key')->comment('Base64 COSE 公钥');
                $table->unsignedBigInteger('counter')->default(0);
                $table->boolean('backup_eligible')->default(false);
                $table->boolean('backup_status')->default(false);
                $table->timestamp('last_used_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index('user_id', 'idx_user_passkeys_user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_passkeys');
    }
};
