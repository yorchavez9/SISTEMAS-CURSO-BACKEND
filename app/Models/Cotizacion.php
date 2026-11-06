<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Cotizacion extends Model
{
    use BelongsToEmpresa;

    /** Transiciones permitidas (la conversión en venta solo ocurre al registrar la venta). */
    public const TRANSICIONES = [
        'borrador' => ['enviada', 'aceptada', 'rechazada'],
        'enviada' => ['aceptada', 'rechazada'],
        'aceptada' => ['rechazada'],
        'rechazada' => [],
        'convertida' => [],
    ];

    protected $table = 'cotizaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_emision' => 'date', 'fecha_validez' => 'date', 'total' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class)->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function codigo(): string
    {
        return sprintf('COT-%06d', $this->numero);
    }

    /** Vencida: pasó su fecha de validez sin cerrarse. Se calcula, no se guarda. */
    public function vencida(): bool
    {
        return in_array($this->estado, ['borrador', 'enviada'], true)
            && $this->fecha_validez->lt(Carbon::now('America/Lima')->startOfDay());
    }

    public function convertible(): bool
    {
        return in_array($this->estado, ['borrador', 'enviada', 'aceptada'], true) && ! $this->vencida();
    }
}
