<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertTemplate extends Model
{
    protected $table = "v2_cert_templates";

    protected $guarded = ["id"];

    protected $casts = [
        "created_at" => "datetime",
        "updated_at" => "datetime",
    ];
}
