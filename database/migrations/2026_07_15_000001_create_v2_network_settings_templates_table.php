<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable("v2_network_settings_templates")) {
            return;
        }

        Schema::create("v2_network_settings_templates", function (
            Blueprint $table,
        ) {
            $table->id();
            $table->string("name", 128)->comment("模板名称");
            $table
                ->string("description", 255)
                ->nullable()
                ->comment("模板描述");
            $table
                ->string("network", 32)
                ->nullable()
                ->index()
                ->comment("关联传输协议，如 ws/grpc/tcp，空为通用");
            $table->json("settings")->comment("network_settings JSON 对象");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("v2_network_settings_templates");
    }
};
