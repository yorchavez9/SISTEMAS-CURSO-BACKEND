<?php

namespace App\Integrations\SunatApi;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * La API limita a 30 solicitudes/minuto por cuenta (X-RateLimit-Limit: 30, hallazgo H-2 de la Etapa 1).
 * Presupuesto compartido entre todos los procesos (cache store con locks atómicos: database o redis),
 * con un margen por debajo del límite real.
 */
class LimitadorSolicitudes
{
    public function __construct(
        private readonly int $porMinuto,
        private readonly int $esperaMaximaSeg,
    ) {}

    public static function desdeConfig(): self
    {
        return new self(
            (int) config('services.sunat_api.rate_limit_por_minuto', 25),
            (int) config('services.sunat_api.rate_limit_espera_max', 75),
        );
    }

    /**
     * Reserva un turno para la cuenta. Si el presupuesto del minuto está agotado, espera
     * hasta que se libere (como máximo $esperaMaximaSeg) y devuelve false si no lo consiguió.
     */
    public function reservar(string $cuenta): bool
    {
        $clave = 'sunat-api:'.$cuenta;
        $esperado = 0;
        while (true) {
            if (RateLimiter::attempt($clave, $this->porMinuto, fn () => true, 60)) {
                return true;
            }
            $faltan = max(1, RateLimiter::availableIn($clave));
            if ($esperado + $faltan > $this->esperaMaximaSeg) {
                return false;
            }
            Sleep::for($faltan)->seconds();
            $esperado += $faltan;
        }
    }
}
