<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    use BelongsToEmpresa;

    /** Métodos de pago. Solo "efectivo" cuenta para el arqueo físico de la caja. */
    public const METODOS = [
        'efectivo' => 'Efectivo', 'tarjeta_debito' => 'Tarjeta de débito', 'tarjeta_credito' => 'Tarjeta de crédito',
        'transferencia' => 'Transferencia', 'yape' => 'Yape', 'plin' => 'Plin', 'deposito' => 'Depósito', 'otro' => 'Otro',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'monto_recibido' => 'decimal:2', 'vuelto' => 'decimal:2', 'anulado_at' => 'datetime'];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
