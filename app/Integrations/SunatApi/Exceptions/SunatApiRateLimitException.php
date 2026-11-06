<?php

namespace App\Integrations\SunatApi\Exceptions;

use RuntimeException;

/** Límite de frecuencia agotado (local o HTTP 429 persistente). Los jobs deben reintentar más tarde. */
class SunatApiRateLimitException extends RuntimeException
{
    public function __construct(string $message, public readonly int $reintentarEnSeg = 60)
    {
        parent::__construct($message);
    }
}
