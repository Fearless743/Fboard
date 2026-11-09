<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 3a37220 起 Trojan 协议定义删除了根级 server_name/allow_insecure，
     * 旧数据若只有根级字段会丢失 SNI。这里再次回填（2026_04_22 的迁移只覆盖当时已有数据）。
     */
    public function up(): void
    {
        DB::table('v2_server')
            ->where('type', 'trojan')
            ->chunkById(100, function ($servers) {
                foreach ($servers as $server) {
                    $settings = json_decode($server->protocol_settings, true);
                    if (!is_array($settings)) {
                        continue;
                    }

                    $legacySni = $settings['server_name'] ?? null;
                    $legacyInsecure = $settings['allow_insecure'] ?? null;
                    $tlsSettings = is_array($settings['tls_settings'] ?? null)
                        ? $settings['tls_settings']
                        : [];

                    $needsUpdate = false;

                    if (($tlsSettings['server_name'] ?? null) === null || ($tlsSettings['server_name'] ?? '') === '') {
                        if ($legacySni !== null && $legacySni !== '') {
                            $tlsSettings['server_name'] = $legacySni;
                            $needsUpdate = true;
                        }
                    }

                    if (!array_key_exists('allow_insecure', $tlsSettings) || $tlsSettings['allow_insecure'] === null) {
                        if ($legacyInsecure !== null) {
                            $tlsSettings['allow_insecure'] = (bool) $legacyInsecure;
                            $needsUpdate = true;
                        }
                    }

                    if (!$needsUpdate) {
                        continue;
                    }

                    $settings['tls_settings'] = $tlsSettings;
                    unset($settings['server_name'], $settings['allow_insecure']);

                    DB::table('v2_server')
                        ->where('id', $server->id)
                        ->update(['protocol_settings' => json_encode($settings)]);
                }
            });
    }

    public function down(): void
    {
    }
};
