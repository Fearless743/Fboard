<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable("v2_cert_templates")) {
            return;
        }

        Schema::create("v2_cert_templates", function (Blueprint $table) {
            $table->id();
            $table->string("name", 128)->comment("模板名称");
            $table
                ->string("description", 255)
                ->nullable()
                ->comment("模板描述");
            $table->longText("cert_content")->comment("证书内容 PEM");
            $table->longText("key_content")->comment("私钥内容 PEM");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("v2_cert_templates");
    }
};
