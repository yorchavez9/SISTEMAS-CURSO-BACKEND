<?php

namespace App\Integrations\SunatApi;

use Illuminate\Http\Client\Response;

/**
 * Envoltorio de respuesta. Formato documentado en la colección:
 * { estado: "exito"|"error", mensaje, datos, meta?, errores? }
 */
final class SunatApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ?array $json,
        public readonly string $raw,
        public readonly string $contentType,
        public readonly int $durationMs,
    ) {}

    public static function fromHttp(Response $r, int $ms): self
    {
        $json = $r->json();

        return new self($r->status(), is_array($json) ? $json : null, $r->body(), (string) $r->header('Content-Type'), $ms);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && ($this->json['estado'] ?? 'exito') !== 'error';
    }

    public function datos(): mixed
    {
        return $this->json['datos'] ?? null;
    }

    public function mensaje(): ?string
    {
        return $this->json['mensaje'] ?? null;
    }

    public function errores(): ?array
    {
        return $this->json['errores'] ?? null;
    }

    /** Busca una clave en `datos` (en anchura) sin asumir la estructura exacta, que aún no está documentada. */
    public function buscar(string ...$claves): mixed
    {
        $cola = [is_array($this->datos()) ? $this->datos() : []];
        while ($cola) {
            $nodo = array_shift($cola);
            foreach ($claves as $c) {
                if (array_key_exists($c, $nodo) && ! is_array($nodo[$c]) && $nodo[$c] !== null && $nodo[$c] !== '') {
                    return $nodo[$c];
                }
            }
            foreach ($nodo as $v) {
                if (is_array($v)) {
                    $cola[] = $v;
                }
            }
        }

        return null;
    }
}
