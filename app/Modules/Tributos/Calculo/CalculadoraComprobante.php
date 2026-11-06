<?php

namespace App\Modules\Tributos\Calculo;

use InvalidArgumentException;

/**
 * Motor de cálculo de comprobantes. Reproduce los totales que la API calcula y SUNAT aceptó en la
 * Etapa 1 (validado con 47 comprobantes reales en tests/Fixtures/etapa1).
 *
 * Entrada: misma forma que el payload de la API (items con precio_unitario incluyendo impuestos,
 * mto_valor_unitario para gratuitas/valor explícito, tip_afe_igv, porcentaje_igv, porcentaje_isc,
 * tip_sis_isc, factor_icbper, descuentos por ítem; descuentos_globales, anticipos, percepcion, detraccion).
 *
 * Reglas (evidencia Etapa 1):
 *  - valor unitario = precio / ((1+ISC)·(1+IGV)) en gravados; = precio en exonerado/inafecto/exportación (F-08, F-14)
 *  - descuento por ítem cód. 00: se resta de la base de la línea (F-11)
 *  - descuento global cód. 02: reduce la base gravada y el IGV se recalcula (F-10)
 *  - descuento global cód. 03: no afecta base ni IGV, se resta del total (F-12)
 *  - anticipos: se restan del total (F-09, B-08)
 *  - ISC sistema 01 al valor; IGV = (base + ISC)·tasa (F-14)
 *  - ICBPER = cantidad·factor, se cobra también en ítems gratuitos (F-13, F-18)
 *  - gratuitas: valor referencial a "gratuitas"; el IGV de la línea se informa pero no suma al documento (F-07, F-17)
 *  - IVAP: tasa del ítem (4 %) sobre "base_ivap" (F-15)
 */
final class CalculadoraComprobante
{
    private const IVAP_DEFECTO = '4';

    /** @param array<string, ReglaAfectacion> $reglas */
    public function __construct(private readonly array $reglas) {}

    public static function conReglasDeBd(): self
    {
        return new self(ReglaAfectacion::todas());
    }

    /**
     * @param  array  $doc  payload (items, descuentos_globales, anticipos, percepcion, detraccion)
     * @param  string|int|float  $tasaIgv  tasa vigente de la empresa en % (18, 10.5, 0 en NRUS…)
     * @return array{items: list<array>, totales: array<string, string>, percepcion: ?array, detraccion: ?array}
     */
    public function calcular(array $doc, string|int|float $tasaIgv): array
    {
        $items = $doc['items'] ?? [];
        if (! $items) {
            throw new InvalidArgumentException('El comprobante debe tener al menos un ítem.');
        }

        $acum = array_fill_keys(['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'base_ivap', 'igv', 'ivap', 'isc', 'icbper'], '0');
        $tasaGravadaDoc = null;
        $outItems = [];

        foreach (array_values($items) as $i => $it) {
            $r = $this->regla((string) ($it['tip_afe_igv'] ?? ''), $i);
            $cant = Dec::n($it['cantidad'] ?? 0);
            if (Dec::cmp($cant, '0') <= 0) {
                throw new InvalidArgumentException("Ítem #".($i + 1).': la cantidad debe ser mayor a cero.');
            }
            $tasa = $this->tasaItem($r, $it, $tasaIgv);
            $tasaIsc = Dec::div(Dec::n($it['porcentaje_isc'] ?? 0), 100);
            if (! Dec::isZero($tasaIsc) && ($it['tip_sis_isc'] ?? '01') !== '01') {
                throw new InvalidArgumentException("Ítem #".($i + 1).': solo se admite ISC sistema 01 (al valor), el único validado con la API.');
            }
            $icbper = Dec::round(Dec::mul($cant, $it['factor_icbper'] ?? 0));

            if ($r->esGratuito) {
                $vuRef = Dec::n($it['mto_valor_unitario'] ?? 0);
                if (Dec::cmp($vuRef, '0') <= 0) {
                    throw new InvalidArgumentException("Ítem #".($i + 1).': una operación gratuita requiere valor referencial (mto_valor_unitario).');
                }
                $base = Dec::round(Dec::mul($cant, $vuRef));
                $igvLinea = $r->calculaIgv ? Dec::round(Dec::mul($base, $tasa)) : '0';
                $acum['gratuitas'] = Dec::add($acum['gratuitas'], $base);
                $acum['icbper'] = Dec::add($acum['icbper'], $icbper);
                $outItems[] = $this->item($it, '0', '0', $base, '0', $igvLinea, '0', $icbper, Dec::add($base, $igvLinea, $icbper), true);

                continue;
            }

            $vu = isset($it['mto_valor_unitario'])
                ? Dec::n($it['mto_valor_unitario'])
                : $this->valorUnitario($r, Dec::n($it['precio_unitario'] ?? 0), $tasa, $tasaIsc);
            $baseBruta = Dec::round(Dec::mul($cant, $vu));
            $descuento = '0';
            foreach ($it['descuentos'] ?? [] as $d) {
                if (($d['cod_tipo'] ?? '00') !== '00') {
                    throw new InvalidArgumentException("Ítem #".($i + 1).": descuento por ítem cód. {$d['cod_tipo']} no soportado (validado: 00).");
                }
                $descuento = Dec::add($descuento, Dec::round($d['monto'] ?? 0));
            }
            if (Dec::cmp($descuento, $baseBruta) > 0) {
                throw new InvalidArgumentException("Ítem #".($i + 1).': el descuento supera el valor de la línea.');
            }
            $base = Dec::sub($baseBruta, $descuento);
            $isc = Dec::round(Dec::mul($base, $tasaIsc));
            $impuesto = '0';
            if (in_array($r->grupo, ['gravado', 'ivap'], true)) {
                $impuesto = Dec::round(Dec::mul(Dec::add($base, $isc), $tasa));
                if (! isset($it['mto_valor_unitario']) && Dec::isZero($descuento)) {
                    // Precio con impuestos: el impuesto es la diferencia, para que el cliente pague exactamente
                    // cantidad × precio (B-07: 10.00 − 8.47 = 1.53, no 8.47 × 18 % = 1.52). Igual a la API.
                    $impuesto = Dec::sub(Dec::sub(Dec::round(Dec::mul($cant, $it['precio_unitario'] ?? 0)), $base), $isc);
                }
            }

            $clave = ['gravado' => 'gravadas', 'exonerado' => 'exoneradas', 'inafecto' => 'inafectas', 'exportacion' => 'exportacion', 'ivap' => 'base_ivap'][$r->grupo];
            $acum[$clave] = Dec::add($acum[$clave], $base);
            $acum['isc'] = Dec::add($acum['isc'], $isc);
            $acum['icbper'] = Dec::add($acum['icbper'], $icbper);
            if ($r->grupo === 'ivap') {
                $acum['ivap'] = Dec::add($acum['ivap'], $impuesto);
            } elseif ($r->grupo === 'gravado') {
                $acum['igv'] = Dec::add($acum['igv'], $impuesto);
                $tasaGravadaDoc ??= $tasa;
            }
            $outItems[] = $this->item($it, $vu, Dec::n($it['precio_unitario'] ?? 0), $base, $isc, $impuesto, $descuento, $icbper, Dec::add($base, $isc, $impuesto, $icbper), false, $baseBruta);
        }

        // Descuentos globales
        $desc02 = '0';
        $desc03 = '0';
        foreach ($doc['descuentos_globales'] ?? [] as $d) {
            match ($d['cod_tipo'] ?? '') {
                '02' => $desc02 = Dec::add($desc02, Dec::round($d['monto'] ?? 0)),
                '03' => $desc03 = Dec::add($desc03, Dec::round($d['monto'] ?? 0)),
                default => throw new InvalidArgumentException("Descuento global cód. {$d['cod_tipo']} no soportado (validados: 02 y 03)."),
            };
        }
        if (! Dec::isZero($desc02)) {
            if (Dec::cmp($desc02, $acum['gravadas']) > 0) {
                throw new InvalidArgumentException('El descuento global supera la base gravada.');
            }
            $acum['gravadas'] = Dec::sub($acum['gravadas'], $desc02);
            // El IGV se recalcula sobre la base gravada neta (+ ISC), como lo hizo la API en F-10.
            $acum['igv'] = Dec::round(Dec::mul(Dec::add($acum['gravadas'], $acum['isc']), $tasaGravadaDoc ?? Dec::div($tasaIgv, 100)));
        }

        $totalImpuestos = Dec::add($acum['igv'], $acum['ivap'], $acum['isc'], $acum['icbper']);
        $valorVenta = Dec::add($acum['gravadas'], $acum['exoneradas'], $acum['inafectas'], $acum['exportacion'], $acum['base_ivap']);
        $subTotal = Dec::add($valorVenta, $totalImpuestos);
        $anticipos = '0';
        foreach ($doc['anticipos'] ?? [] as $a) {
            $anticipos = Dec::add($anticipos, Dec::round($a['monto'] ?? 0));
        }
        $total = Dec::sub(Dec::sub($subTotal, $desc03), $anticipos);
        if (Dec::cmp($total, '0') < 0) {
            throw new InvalidArgumentException('Los descuentos y anticipos superan el importe del comprobante.');
        }

        $totales = array_map(fn ($v) => Dec::round($v), $acum) + [
            'total_impuestos' => Dec::round($totalImpuestos),
            'valor_venta' => Dec::round($valorVenta),
            'sub_total' => Dec::round($subTotal),
            'descuentos_globales' => Dec::round(Dec::add($desc02, $desc03)),
            'anticipos' => Dec::round($anticipos),
            'total' => Dec::round($total),
        ];

        return [
            'items' => $outItems,
            'totales' => $totales,
            'percepcion' => $this->porcentajeSobreTotal($doc['percepcion'] ?? null, $total),
            'detraccion' => $this->porcentajeSobreTotal($doc['detraccion'] ?? null, $total),
        ];
    }

    private function regla(string $codigo, int $i): ReglaAfectacion
    {
        $r = $this->reglas[$codigo] ?? throw new InvalidArgumentException("Ítem #".($i + 1).": tipo de afectación '$codigo' no existe en el catálogo 07.");
        if (! $r->activo) {
            throw new InvalidArgumentException("Ítem #".($i + 1).": el tipo de afectación $codigo no está disponible (ver Configuración tributaria).");
        }

        return $r;
    }

    /** Tasa como fracción (0.18). El ítem puede fijarla (porcentaje_igv); IVAP usa 4 % por defecto. */
    private function tasaItem(ReglaAfectacion $r, array $it, string|int|float $tasaEmpresa): string
    {
        if (isset($it['porcentaje_igv'])) {
            return Dec::div($it['porcentaje_igv'], 100);
        }

        return Dec::div($r->grupo === 'ivap' ? self::IVAP_DEFECTO : Dec::n($tasaEmpresa), 100);
    }

    private function valorUnitario(ReglaAfectacion $r, string $precio, string $tasa, string $tasaIsc): string
    {
        return match ($r->grupo) {
            'gravado' => Dec::div($precio, Dec::mul(Dec::add('1', $tasaIsc), Dec::add('1', $tasa))),
            'ivap' => Dec::div($precio, Dec::add('1', $tasa)),
            default => $precio,
        };
    }

    private function item(array $it, string $vu, string $precio, string $base, string $isc, string $igv, string $descuento, string $icbper, string $total, bool $gratuito, ?string $baseBruta = null): array
    {
        return [
            'codigo' => $it['codigo'] ?? null,
            'descripcion' => $it['descripcion'] ?? null,
            'unidad' => $it['unidad'] ?? null,
            'cantidad' => Dec::n($it['cantidad']),
            'tip_afe_igv' => (string) $it['tip_afe_igv'],
            'gratuito' => $gratuito,
            'precio_unitario' => Dec::round($precio, 10),
            'valor_unitario' => Dec::round($vu, 4),
            'valor_referencial' => $gratuito ? Dec::n($it['mto_valor_unitario']) : null,
            'base_bruta' => $baseBruta !== null ? Dec::round($baseBruta) : Dec::round($base),
            'descuento' => Dec::round($descuento),
            'base' => Dec::round($base),
            'isc' => Dec::round($isc),
            'igv' => Dec::round($igv),
            'icbper' => Dec::round($icbper),
            'total' => Dec::round($total),
        ];
    }

    private function porcentajeSobreTotal(?array $def, string $total): ?array
    {
        if (! $def || ! isset($def['porcentaje'])) {
            return null;
        }
        $base = isset($def['base']) ? Dec::round($def['base']) : Dec::round($total);

        return ['porcentaje' => Dec::n($def['porcentaje']), 'base' => $base, 'monto' => Dec::round(Dec::div(Dec::mul($base, $def['porcentaje']), 100))];
    }
}
