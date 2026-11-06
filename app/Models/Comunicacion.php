<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comunicacion extends Model
{
    use BelongsToEmpresa;

    public const FINALES = ['aceptado', 'rechazado'];

    protected $table = 'comunicaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_documentos' => 'date', 'payload' => 'array', 'efectos_aplicados' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComunicacionItem::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recursoApi(): string
    {
        return $this->tipo === 'RA' ? 'anulaciones' : 'resumenes';
    }

    public function esFinal(): bool
    {
        return in_array($this->estado, self::FINALES, true);
    }
}
