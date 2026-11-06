<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Venta extends Model
{
    use BelongsToEmpresa;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'tasa_igv' => 'decimal:2', 'total' => 'decimal:2', 'monto_pagado' => 'decimal:2', 'saldo' => 'decimal:2',
            'total_notas_credito' => 'decimal:2', 'total_notas_debito' => 'decimal:2', 'anulada_por_baja' => 'boolean'];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(VentaCuota::class)->orderBy('numero');
    }

    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class)->latestOfMany();
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class)->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
