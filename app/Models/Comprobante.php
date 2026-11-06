<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comprobante extends Model
{
    use BelongsToEmpresa;

    public const FINALES = ['aceptado', 'observado', 'rechazado', 'anulado'];

    /** Recurso de la API por tipo de documento (colección Postman). */
    public const RECURSO_API = ['01' => 'facturas', '03' => 'boletas', '07' => 'notas-credito', '08' => 'notas-debito'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date', 'fecha_vencimiento' => 'date', 'payload' => 'array', 'totales_api' => 'array',
            'sunat_notas' => 'array', 'diferencia_totales' => 'boolean', 'enviado_at' => 'datetime', 'aceptado_at' => 'datetime',
            'total' => 'decimal:2', 'envio_por_resumen' => 'boolean',
        ];
    }

    public function numero(): string
    {
        return $this->serie.'-'.$this->correlativo;
    }

    public function recursoApi(): string
    {
        return self::RECURSO_API[$this->tipo_documento];
    }

    public function esFinal(): bool
    {
        return in_array($this->estado, self::FINALES, true);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function estados(): HasMany
    {
        return $this->hasMany(ComprobanteEstado::class)->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComprobanteItem::class);
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(self::class, 'comprobante_referencia_id');
    }

    /** Notas de crédito/débito que modifican este comprobante. */
    public function notas(): HasMany
    {
        return $this->hasMany(self::class, 'comprobante_referencia_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(ComprobanteArchivo::class);
    }

    public function cambiarEstado(string $nuevo, ?string $detalle = null, array $extra = []): void
    {
        $anterior = $this->estado;
        $this->fill(['estado' => $nuevo] + $extra)->save();
        if ($anterior !== $nuevo || $detalle) {
            $this->estados()->create(['estado_anterior' => $anterior, 'estado_nuevo' => $nuevo, 'detalle' => $detalle ? mb_substr($detalle, 0, 1000) : null]);
        }
    }
}
