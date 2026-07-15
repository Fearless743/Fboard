<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkSettingsTemplate extends Model
{
    protected $table = "v2_network_settings_templates";

    protected $guarded = ["id"];

    protected $casts = [
        "settings" => "array",
        "created_at" => "datetime",
        "updated_at" => "datetime",
    ];
}
