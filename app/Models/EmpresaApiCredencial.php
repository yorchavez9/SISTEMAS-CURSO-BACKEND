<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenciales de la API de facturación por empresa y entorno.
 * api_key y api_secret se cifran con APP_KEY (cast "encrypted") y nunca se serializan.
 */
class EmpresaApiCredencial extends Model
{
    protected $table = 'empresa_api_credenciales';

    protected $fillable = [
        'empresa_id', 'entorno', 'api_key', 'api_secret', 'api_key_ultimos4',
        'verificado_at', 'verificacion_ok', 'verificacion_mensaje', 'remoto', 'updated_by',
    ];

    protected $hidden = ['api_key', 'api_secret'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'verificado_at' => 'datetime',
            'verificacion_ok' => 'boolean',
            'remoto' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
