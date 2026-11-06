<?php

namespace Tests\Feature\Tributos;

use App\Modules\Tributos\Calculo\CalculadoraComprobante;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CalculadoraValidacionesTest extends TestCase
{
    use RefreshDatabase;

    private function calc(array $doc, $tasa = 18): array
    {
        return CalculadoraComprobante::conReglasDeBd()->calcular($doc, $tasa);
    }

    private function item(array $o = []): array
    {
        return $o + ['codigo' => 'P1', 'descripcion' => 'Prod', 'unidad' => 'NIU', 'cantidad' => 1, 'precio_unitario' => 118, 'tip_afe_igv' => '10'];
    }

    public function test_codigo_37_no_disponible_por_defecto_de_la_api(): void
    {
        $this->expectExceptionMessage('37 no está disponible');
        $this->calc(['items' => [$this->item(['tip_afe_igv' => '37', 'precio_unitario' => 0, 'mto_valor_unitario' => 100])]]);
    }

    public function test_codigo_inexistente(): void
    {
        $this->expectExceptionMessage("'99' no existe");
        $this->calc(['items' => [$this->item(['tip_afe_igv' => '99'])]]);
    }

    public function test_validaciones_de_items(): void
    {
        $casos = [
            'sin ítems' => [[], 'al menos un ítem'],
            'cantidad cero' => [[$this->item(['cantidad' => 0])], 'mayor a cero'],
            'gratuita sin valor' => [[$this->item(['tip_afe_igv' => '13', 'precio_unitario' => 0])], 'valor referencial'],
            'descuento excesivo' => [[$this->item(['descuentos' => [['cod_tipo' => '00', 'monto' => 101]]])], 'supera el valor'],
            'ISC sistema 02' => [[$this->item(['porcentaje_isc' => 10, 'tip_sis_isc' => '02'])], 'sistema 01'],
        ];
        foreach ($casos as $nombre => [$items, $msg]) {
            try {
                $this->calc(['items' => $items]);
                $this->fail("Debió fallar: $nombre");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($msg, $e->getMessage(), $nombre);
            }
        }
    }

    public function test_descuentos_y_anticipos_no_dejan_total_negativo(): void
    {
        $this->expectExceptionMessage('superan el importe');
        $this->calc(['items' => [$this->item()], 'anticipos' => [['monto' => 200]]]);
    }

    public function test_detraccion_se_calcula_sobre_el_total(): void
    {
        // La API aceptó 37.76 tal como se envió en Postman (I-6); el sistema calcula el 4 % real de 1000.
        $r = $this->calc(['items' => [$this->item(['precio_unitario' => 1000])], 'detraccion' => ['porcentaje' => 4]]);
        $this->assertSame('40.00', $r['detraccion']['monto']);
    }

    public function test_tasa_de_la_empresa_nrus_y_tasa_reducida(): void
    {
        $this->assertSame('0.00', $this->calc(['items' => [$this->item(['precio_unitario' => 50])]], 0)['totales']['igv']);
        $r = $this->calc(['items' => [$this->item(['precio_unitario' => 27.5])]], 10.5);
        $this->assertSame('24.89', $r['totales']['gravadas']);   // 27.5 / 1.105
        $this->assertSame('2.61', $r['totales']['igv']);          // 27.50 − 24.89
        $this->assertSame('27.50', $r['totales']['total']);
    }

    public function test_redondeo_half_up_exacto(): void
    {
        $this->assertSame('0.04', Dec::round('0.035'));
        $this->assertSame('1.53', Dec::round('1.525'));
        $this->assertSame('-0.04', Dec::round('-0.035'));
        $this->assertSame('847.4576', Dec::round(Dec::div('1000', '1.18'), 4));
    }
}
