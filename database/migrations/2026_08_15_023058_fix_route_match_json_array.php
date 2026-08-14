<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix route match JSON stored as object (e.g. {"0":"a","2":"b"}) instead of array.
     * This happens when array_filter() removes empty values but preserves non-sequential keys.
     */
    public function up(): void
    {
        $routes = DB::table('v2_server_route')->get(['id', 'match']);

        foreach ($routes as $route) {
            $decoded = json_decode($route->match, true);
            if (!is_array($decoded)) {
                continue;
            }
            // Check if it's an associative object (non-sequential keys) rather than a list
            $isObject = array_keys($decoded) !== range(0, count($decoded) - 1);
            if ($isObject) {
                $fixed = array_values($decoded);
                DB::table('v2_server_route')
                    ->where('id', $route->id)
                    ->update(['match' => json_encode($fixed)]);
            }
        }
    }

    /**
     * Reverse the migration — no-op, cannot restore corrupted data.
     */
    public function down(): void
    {
        // No-op
    }
};