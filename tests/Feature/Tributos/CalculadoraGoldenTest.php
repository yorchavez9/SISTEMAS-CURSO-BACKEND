<?php

namespace Tests\Feature\Tributos;

use App\Modules\Tributos\Calculo\CalculadoraComprobante;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prueba "golden": el motor local debe producir EXACTAMENTE los totales que la API devolvió para
 * comprobantes que SUNAT beta aceptó en la Etapa 1 (tests/Fixtures/etapa1, empresa con IGV 18 %).
 * Un fixture por escenario Postman (F-xx, B-xx, NC-xx, ND-xx) y por afectación sin ejemplo (AF-xx).
 */
class CalculadoraGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const TASA_IGV_EMPRESA = 18;

    /** Totales que la API informa (si no aparecen, deben ser 0 en el cálculo local). */
    private const CLAVES = ['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'base_ivap', 'igv', 'ivap', 'isc', 'icbper', 'total_impuestos', 'valor_venta', 'sub_total', 'total'];

    public static function fixtures(): array
    {
        $out = [];
        foreach (glob(__DIR__.'/../../Fixtures/etapa1/*.json') as $f) {
            $fx = json_decode(file_get_contents($f), true);
            $out[$fx['alias'].' · '.$fx['comprobante'].' · '.$fx['postman']] = [$fx];
        }

        return $out;
    }

    #[DataProvider('fixtures')]
    public function test_reproduce_los_totales_aceptados_por_sunat(array $fx): void
    {
        $r = CalculadoraComprobante::conReglasDeBd()->calcular($fx['request'], self::TASA_IGV_EMPRESA);
        $api = $fx['respuesta']['totales'];

        foreach (self::CLAVES as $k) {
            $esperado = Dec::round($api[$k] ?? 0);
            $this->assertSame($esperado, $r['totales'][$k], "{$fx['alias']}: total '$k' (API: ".($api[$k] ?? 'ausente').')');
        }

        // H-12: en notas de crédito/débito la API informa items[].total SIN impuestos (= base de la línea).
        $esNota = str_starts_with($fx['alias'], 'NC-') || str_starts_with($fx['alias'], 'ND-');
        foreach ($fx['respuesta']['items'] as $i => $itApi) {
            $it = $r['items'][$i];
            foreach (['igv', 'isc', 'icbper', 'total'] as $k) {
                if (array_key_exists($k, $itApi)) {
                    $local = ($k === 'total' && $esNota) ? $it['base'] : $it[$k];
                    $this->assertSame(Dec::round($itApi[$k]), $local, "{$fx['alias']} ítem #".($i + 1).": '$k'");
                }
            }
            if (! $it['gratuito']) {
                $this->assertSame(Dec::round($itApi['valor_unitario'], 4), $it['valor_unitario'], "{$fx['alias']} ítem #".($i + 1).': valor_unitario');
            }
            if (isset($itApi['descuento'])) {
                $this->assertSame(Dec::round($itApi['descuento']), $it['descuento'], "{$fx['alias']} ítem #".($i + 1).': descuento');
            }
        }

        if ($p = $fx['respuesta']['percepcion'] ?? null) {
            $this->assertSame(Dec::round($p['monto']), $r['percepcion']['monto'], "{$fx['alias']}: percepción");
        }
    }

    public function test_hay_un_fixture_por_cada_escenario_aceptado(): void
    {
        $this->assertCount(47, self::fixtures());
    }
}
