<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiSolicitud extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'api_solicitudes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'respuesta' => 'array'];
    }
}
