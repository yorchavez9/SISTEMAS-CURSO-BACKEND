<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CajaSesion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'caja_sesiones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['apertura_at' => 'datetime', 'cierre_at' => 'datetime', 'resumen_cierre' => 'array', 'monto_apertura' => 'decimal:2'];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CajaMovimiento::class);
    }
}
