<?php

namespace App\Modules\Facturacion\Payloads;

/**
 * Construye el JSON de POST /facturas y POST /boletas con la estructura de los ejemplos Postman
 * aceptados en la Etapa 1 (F-01…F-24, B-01…B-08). Correlativo SIEMPRE explícito (decisión D3).
 */
final class ComprobantePayloadBuilder
{
    /**
     * @param  array  $cab  serie, correlativo, fecha_emision (Y-m-d), tipo_operacion, moneda, forma_pago, fecha_vencimiento?
     * @param  array  $cliente  forma inline {tipo_doc, num_doc, razon_social, direccion?}
     * @param  list<array>  $items  ítems con la forma de la API
     * @param  list<array{monto: string|float, fecha_vencimiento: string}>  $cuotas
     * @param  list<array{cod_tipo: string, monto: string|float}>  $descuentosGlobales
     */
    public static function construir(array $cab, array $cliente, array $items, array $cuotas = [], array $descuentosGlobales = []): array
    {
        $p = [
            'serie' => $cab['serie'],
            'correlativo' => (int) $cab['correlativo'],
            'fecha_emision' => $cab['fecha_emision'],
            'tipo_operacion' => $cab['tipo_operacion'],
            'tipo_moneda' => $cab['moneda'],
            'forma_pago' => $cab['forma_pago'] === 'credito' ? 'Credito' : 'Contado', // literal usado en F-03
            'cliente' => $cliente,
            'items' => array_values($items),
            'enviar_automatico' => true,
        ];
        if ($cab['forma_pago'] === 'credito') {
            $p['fecha_vencimiento'] = $cab['fecha_vencimiento'];
            $p['cuotas'] = array_map(fn ($c) => ['monto' => (float) $c['monto'], 'fecha_pago' => $c['fecha_vencimiento']], $cuotas);
        }
        if ($descuentosGlobales) {
            $p['descuentos_globales'] = array_map(fn ($d) => ['cod_tipo' => $d['cod_tipo'], 'monto' => (float) $d['monto']], $descuentosGlobales);
        }

        return $p;
    }
}
