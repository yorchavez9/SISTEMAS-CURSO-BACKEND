<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Serie extends Model
{
    use BelongsToEmpresa;

    protected $table = 'series';

    /** Catálogo 01 ↔ nombre de tipo que usa la API en POST /series. */
    public const TIPOS_API = [
        '01' => 'factura', '03' => 'boleta', '07' => 'nota_credito', '08' => 'nota_debito',
        '09' => 'guia_remision', '31' => 'guia_transportista', '20' => 'retencion', '40' => 'percepcion',
    ];

    /** Prefijo exigido por SUNAT para cada tipo (las notas usan F o B según el documento que modifican). */
    public const PREFIJOS = [
        '01' => ['F'], '03' => ['B'], '07' => ['F', 'B'], '08' => ['F', 'B'], '09' => ['T'], '31' => ['V'], '20' => ['R'], '40' => ['P'],
    ];

    protected $fillable = [
        'empresa_id', 'establecimiento_id', 'tipo_documento', 'serie', 'correlativo_actual', 'activo', 'predeterminada', 'api_serie_id', 'sincronizado_at',
    ];

    protected $attributes = ['correlativo_actual' => 0, 'activo' => true];

    protected function casts(): array
    {
        return ['correlativo_actual' => 'integer', 'activo' => 'boolean', 'predeterminada' => 'boolean', 'sincronizado_at' => 'datetime'];
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    public function siguienteNumero(): string
    {
        return $this->serie.'-'.($this->correlativo_actual + 1);
    }
}
