<?php

namespace App\Modules\Tributos\Calculo;

/**
 * Aritmética decimal exacta (bcmath) para montos tributarios. Nunca se usa float en los cálculos.
 * Redondeo: medio hacia arriba (half-up), como SUNAT.
 */
final class Dec
{
    private const ESCALA = 12;

    public static function n(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (is_float($v)) {
            // Representación decimal mínima sin notación científica.
            $v = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        }
        $s = trim((string) $v);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException("Número inválido: '$s'");
        }

        return $s;
    }

    public static function add(mixed ...$xs): string
    {
        $r = '0';
        foreach ($xs as $x) {
            $r = bcadd($r, self::n($x), self::ESCALA);
        }

        return $r;
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return bcsub(self::n($a), self::n($b), self::ESCALA);
    }

    public static function mul(mixed $a, mixed $b): string
    {
        return bcmul(self::n($a), self::n($b), self::ESCALA);
    }

    public static function div(mixed $a, mixed $b): string
    {
        if (bccomp(self::n($b), '0', self::ESCALA) === 0) {
            throw new \DivisionByZeroError('División entre cero en cálculo tributario.');
        }

        return bcdiv(self::n($a), self::n($b), self::ESCALA);
    }

    /** Redondeo half-up a $d decimales. */
    public static function round(mixed $v, int $d = 2): string
    {
        $v = self::n($v);
        $ajuste = '0.'.str_repeat('0', $d).'5';
        $r = str_starts_with($v, '-') ? bcsub($v, $ajuste, $d) : bcadd($v, $ajuste, $d);

        return $r === '-0'.($d ? '.'.str_repeat('0', $d) : '') ? substr($r, 1) : $r;
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::n($a), self::n($b), self::ESCALA);
    }

    public static function isZero(mixed $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** Para JSON/presentación: número sin ceros sobrantes (no se usa para calcular). */
    public static function f(mixed $v): float
    {
        return (float) self::n($v);
    }
}
