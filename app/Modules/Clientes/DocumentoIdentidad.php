<?php

namespace App\Modules\Clientes;

/** Validación de documentos de identidad (catálogo 06). */
final class DocumentoIdentidad
{
    public const TIPOS = ['0', '1', '4', '6', '7', 'A'];

    /** @return string|null mensaje de error, o null si es válido */
    public static function error(string $tipo, string $numero): ?string
    {
        return match ($tipo) {
            '1' => preg_match('/^\d{8}$/', $numero) ? null : 'El DNI debe tener 8 dígitos.',
            '6' => self::errorRuc($numero),
            '4' => preg_match('/^[A-Za-z0-9]{1,12}$/', $numero) ? null : 'El carné de extranjería tiene hasta 12 caracteres alfanuméricos.',
            '7' => preg_match('/^[A-Za-z0-9]{1,12}$/', $numero) ? null : 'El pasaporte tiene hasta 12 caracteres alfanuméricos.',
            'A' => preg_match('/^[A-Za-z0-9]{1,15}$/', $numero) ? null : 'La cédula diplomática tiene hasta 15 caracteres alfanuméricos.',
            '0' => preg_match('/^[A-Za-z0-9-]{1,15}$/', $numero) ? null : 'Documento de hasta 15 caracteres.',
            default => 'Tipo de documento no válido.',
        };
    }

    public static function errorRuc(string $ruc): ?string
    {
        if (! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) {
            return 'El RUC debe tener 11 dígitos y empezar con 10, 15, 16, 17 o 20.';
        }

        return self::digitoVerificadorRuc(substr($ruc, 0, 10)) === (int) $ruc[10]
            ? null
            : 'El RUC no es válido (dígito verificador incorrecto).';
    }

    /** Algoritmo módulo 11 de SUNAT (pesos 5,4,3,2,7,6,5,4,3,2). */
    public static function digitoVerificadorRuc(string $primeros10): int
    {
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($pesos as $i => $p) {
            $suma += (int) $primeros10[$i] * $p;
        }
        $d = 11 - ($suma % 11);

        return match ($d) { 10 => 0, 11 => 1, default => $d };
    }
}
