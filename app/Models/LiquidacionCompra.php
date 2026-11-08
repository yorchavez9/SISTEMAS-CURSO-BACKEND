<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liquidación de compra (tipo 04). */
class LiquidacionCompra extends Model
{
    use BelongsToEmpresa;

    public const FINALES = ['aceptado', 'rechazado', 'revertido'];

    /** Causales que admite POST /liquidaciones-compra/{id}/reversion. */
    public const CAUSALES = ['vendedor_nombre' => 'Error en el nombre del vendedor', 'vendedor_documento' => 'Error en el documento del vendedor', 'producto' => 'Error en el producto'];

    protected $table = 'liquidaciones_compra';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date', 'productos_agropecuarios' => 'boolean', 'items' => 'array', 'payload' => 'array', 'historial' => 'array', 'archivos' => 'array',
            'sunat_notas' => 'array', 'valor_venta' => 'decimal:2', 'igv' => 'decimal:2', 'porcentaje_renta' => 'decimal:3', 'renta' => 'decimal:2',
            'neto_a_pagar' => 'decimal:2', 'monto_pagado' => 'decimal:2',
        ];
    }

    public function numero(): string
    {
        return $this->serie.'-'.$this->correlativo;
    }

    public function esFinal(): bool
    {
        return in_array($this->estado, self::FINALES, true);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cambiarEstado(string $nuevo, ?string $detalle = null, array $extra = []): void
    {
        $historial = $this->historial ?? [];
        if ($this->estado !== $nuevo || $detalle) {
            $historial[] = ['fecha' => now()->toIso8601String(), 'estado' => $nuevo, 'detalle' => $detalle ? mb_substr($detalle, 0, 1000) : null];
        }
        $this->fill(['estado' => $nuevo, 'historial' => $historial] + $extra)->save();
    }

    public function anotar(string $detalle, array $extra = []): void
    {
        $historial = $this->historial ?? [];
        $historial[] = ['fecha' => now()->toIso8601String(), 'estado' => $this->estado, 'detalle' => mb_substr($detalle, 0, 1000)];
        $this->fill(['historial' => $historial] + $extra)->save();
    }
}
