<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuiaRemision extends Model
{
    use BelongsToEmpresa;

    public const FINALES = ['aceptado', 'rechazado'];

    /** Catálogo 20 (motivos de traslado) ofrecido en la emisión. 01 y 04 tienen ejemplo en Postman. */
    public const MOTIVOS = [
        '01' => 'Venta',
        '02' => 'Compra',
        '03' => 'Venta con entrega a terceros',
        '04' => 'Traslado entre establecimientos de la misma empresa',
        '05' => 'Consignación',
        '06' => 'Devolución',
        '13' => 'Otros',
        '14' => 'Venta sujeta a confirmación del comprador',
        '17' => 'Traslado de bienes para transformación',
    ];

    /** Catálogo 18. */
    public const MODALIDADES = ['01' => 'Transporte público', '02' => 'Transporte privado'];

    protected $table = 'guias_remision';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date', 'fecha_traslado' => 'date', 'payload' => 'array', 'archivos' => 'array', 'historial' => 'array',
            'peso_total' => 'decimal:3', 'enviado_at' => 'datetime', 'aceptado_at' => 'datetime',
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

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    /** Cambia de estado y deja constancia en el historial. */
    public function cambiarEstado(string $nuevo, ?string $detalle = null, array $extra = []): void
    {
        $anterior = $this->estado;
        $historial = $this->historial ?? [];
        if ($anterior !== $nuevo || $detalle) {
            $historial[] = ['fecha' => now()->toIso8601String(), 'estado' => $nuevo, 'detalle' => $detalle ? mb_substr($detalle, 0, 1000) : null];
        }
        $this->fill(['estado' => $nuevo, 'historial' => $historial] + $extra)->save();
    }
}
