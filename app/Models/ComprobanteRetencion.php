<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comprobante de retención (20) o de percepción (40). */
class ComprobanteRetencion extends Model
{
    use BelongsToEmpresa;

    public const FINALES = ['aceptado', 'rechazado', 'revertido'];

    /** Catálogo 23: régimen de retención y su tasa. */
    public const REGIMENES_RETENCION = ['01' => ['Tasa 3%', 3], '02' => ['Tasa 6%', 6]];

    /** Catálogo 22: régimen de percepción y su tasa. */
    public const REGIMENES_PERCEPCION = [
        '01' => ['Venta interna (2%)', 2], '02' => ['Adquisición de combustible (1%)', 1], '03' => ['Agente de percepción con tasa especial (0.5%)', 0.5],
    ];

    /** Comprobantes que se pueden relacionar (01 factura, 03 boleta, 12 ticket). */
    public const DOCUMENTOS = ['01' => 'Factura', '03' => 'Boleta de venta', '12' => 'Ticket'];

    protected $table = 'comprobantes_retencion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date', 'tasa' => 'decimal:3', 'importe_documentos' => 'decimal:2', 'importe' => 'decimal:2', 'importe_neto' => 'decimal:2',
            'documentos' => 'array', 'payload' => 'array', 'historial' => 'array', 'archivos' => 'array',
        ];
    }

    public function esRetencion(): bool
    {
        return $this->tipo_documento === '20';
    }

    /** Recurso de la API: /retenciones o /percepciones. */
    public function recursoApi(): string
    {
        return $this->esRetencion() ? 'retenciones' : 'percepciones';
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

    /** Anota en el historial sin cambiar el estado del comprobante (p. ej. avances de la reversión). */
    public function anotar(string $detalle, array $extra = []): void
    {
        $historial = $this->historial ?? [];
        $historial[] = ['fecha' => now()->toIso8601String(), 'estado' => $this->estado, 'detalle' => mb_substr($detalle, 0, 1000)];
        $this->fill(['historial' => $historial] + $extra)->save();
    }
}
