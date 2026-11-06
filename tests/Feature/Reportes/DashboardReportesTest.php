<?php

namespace Tests\Feature\Reportes;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Serie;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardReportesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private array $s = [];
    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create(['name' => 'Ana Admin']);
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 14]);
        foreach ([['01', 'F001'], ['03', 'B001'], ['07', 'FC01']] as [$t, $n]) {
            $this->s[$n] = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => $t, 'serie' => $n, 'api_serie_id' => crc32($n)]);
        }
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);

        // Factura 118 (aceptada) + boleta 59 (aceptada) + boleta 30 (rechazada, no cuenta) + NC 23.60 sobre la factura
        $f = $this->venta('01', 'F001', 'Laptop', 118, $this->cliente->id);
        $this->venta('03', 'B001', 'Mouse', 59, null);
        $rech = $this->venta('03', 'B001', 'Cable', 30, null);
        $rech->update(['estado' => 'rechazado']);
        $this->actingAs($this->admin)->postJson('/api/v1/notas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '07', 'comprobante_id' => $f->id,
            'serie_id' => $this->s['FC01']->id, 'cod_motivo' => '04', 'des_motivo' => 'Descuento comercial', 'items' => [['descripcion' => 'Descuento', 'precio_unitario' => 23.6]]])->assertCreated();
    }

    private function venta(string $tipo, string $serie, string $desc, float $precio, ?int $cliente): Comprobante
    {
        $this->actingAs($this->admin)->postJson('/api/v1/ventas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => $tipo, 'serie_id' => $this->s[$serie]->id,
            'cliente_id' => $cliente, 'items' => [['descripcion' => $desc, 'cantidad' => 1, 'precio_unitario' => $precio, 'tip_afe_igv' => '10']]])->assertCreated();
        $c = Comprobante::withoutGlobalScopes()->latest('id')->first();
        $c->update(['estado' => 'aceptado', 'api_id' => random_int(1000, 99999)]);

        return $c;
    }

    public function test_dashboard_venta_neta_y_composicion(): void
    {
        $d = $this->actingAs($this->admin)->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertEquals(153.4, $d['kpis']['ventas_hoy']);   // 118 + 59 − 23.60 (la rechazada no cuenta)
        $this->assertEquals(153.4, $d['kpis']['ventas_mes']);
        $this->assertSame(3, $d['kpis']['comprobantes_mes']);
        $this->assertEquals(1, collect($d['por_estado'])->firstWhere('estado', 'rechazado')['cantidad']);
        $this->assertCount(30, $d['ventas_por_dia']);
        $this->assertEquals(153.4, end($d['ventas_por_dia'])['total']);
        $this->assertSame('Laptop', $d['top_productos'][0]['descripcion']);
        $this->assertEquals(94.4, $d['top_clientes'][0]['total']);  // SUNAT: 118 − 23.60; consumidor final excluido
        $this->assertNotEmpty(collect($d['alertas'])->where('nivel', 'critical'));
    }

    public function test_reporte_de_ventas_por_agrupacion(): void
    {
        $hoy = now('America/Lima')->toDateString();
        $q = fn ($g) => $this->actingAs($this->admin)->getJson("/api/v1/reportes/ventas?desde=$hoy&hasta=$hoy&agrupar=$g")->assertOk()->json('data');

        $this->assertEquals(153.4, $q('dia')['totales']['total']);
        $tipos = collect($q('tipo')['filas'])->keyBy('clave');
        $this->assertEquals([118, 59, -23.6], [$tipos['01']['total'], $tipos['03']['total'], $tipos['07']['total']]);
        $this->assertEquals(153.4, $q('usuario')['filas'][0]['total']);
        $this->assertSame('Ana Admin', $q('usuario')['filas'][0]['etiqueta']);
        $this->assertEquals(177, $q('producto')['totales']['total']); // productos vendidos (sin descontar NC por monto)
        $this->actingAs($this->admin)->getJson("/api/v1/reportes/ventas?desde=$hoy&hasta=2020-01-01&agrupar=dia")->assertStatus(422);
    }

    public function test_registro_de_ventas_con_signo_y_anulados_en_cero(): void
    {
        $hoy = now('America/Lima')->toDateString();
        $r = $this->actingAs($this->admin)->getJson("/api/v1/reportes/registro-ventas?desde=$hoy&hasta=$hoy")->assertOk()->json('data');
        $this->assertCount(4, $r['filas']);
        $nc = collect($r['filas'])->firstWhere('tipo', '07');
        $this->assertEquals(-23.6, $nc['total']);
        $this->assertEquals(-3.6, $nc['igv']);
        $this->assertSame('F001-1', $nc['referencia']);
        $this->assertEquals(0, collect($r['filas'])->firstWhere('estado', 'rechazado')['total']);
        $this->assertEquals(153.4, $r['totales']['total']);
        $this->assertEquals(23.4, $r['totales']['igv']); // 18 + 9 − 3.60
    }

    public function test_saldo_a_favor_y_reembolso(): void
    {
        $cajas = $this->actingAs($this->admin)->getJson('/api/v1/caja/estado')->json('data.cajas');
        $this->actingAs($this->admin)->postJson('/api/v1/caja/abrir', ['caja_id' => $cajas[0]['id'], 'monto_apertura' => 100])->assertCreated();
        $v = Venta::withoutGlobalScopes()->whereHas('comprobante', fn ($q) => $q->where('serie', 'F001'))->first();
        $this->actingAs($this->admin)->postJson("/api/v1/ventas/{$v->id}/pagos", ['pagos' => [['metodo' => 'efectivo', 'monto' => 94.4]]])->assertCreated();
        // La factura se da de baja después de cobrada → saldo a favor
        $v->update(['anulada_por_baja' => true, 'estado' => 'anulada']);
        app(\App\Modules\Caja\Services\PagoService::class)->recalcular($v);

        $cxc = $this->actingAs($this->admin)->getJson('/api/v1/cuentas-por-cobrar')->json('data');
        $this->assertEquals(94.4, $cxc['saldos_a_favor'][0]['a_favor']);
        $this->actingAs($this->admin)->postJson("/api/v1/ventas/{$v->id}/reembolso", ['metodo' => 'efectivo', 'monto' => 100])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/v1/ventas/{$v->id}/reembolso", ['metodo' => 'efectivo', 'monto' => 94.4])->assertCreated();
        $this->assertSame('0.00', (string) $v->fresh()->saldo);
        $this->assertDatabaseHas('audit_logs', ['evento' => 'pago.reembolso', 'empresa_id' => $this->empresa->id]);
        $this->assertCount(0, $this->actingAs($this->admin)->getJson('/api/v1/cuentas-por-cobrar')->json('data.saldos_a_favor'));
        $this->assertSame('100.00', $this->actingAs($this->admin)->getJson('/api/v1/caja/estado')->json('data.sesion.resumen.efectivo_esperado'));
    }

    public function test_registro_de_ventas_en_excel_coincide_con_el_reporte(): void
    {
        $hoy = now('America/Lima')->toDateString();
        $r = $this->actingAs($this->admin)->get("/api/v1/reportes/registro-ventas/excel?desde=$hoy&hasta=$hoy")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $r->headers->get('Content-Type'));
        $this->assertStringContainsString("registro-ventas-{$this->empresa->ruc}-$hoy-al-$hoy.xlsx", $r->headers->get('Content-Disposition'));

        $tmp = tempnam(sys_get_temp_dir(), 'rv').'.xlsx';
        file_put_contents($tmp, $r->getContent());
        $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $h = $libro->getSheetByName('Registro');
        $this->assertSame('REGISTRO DE VENTAS E INGRESOS', $h->getCell('A1')->getValue());
        $this->assertSame('Fecha emisión', $h->getCell('A5')->getValue());
        // 4 comprobantes (filas 6-9) y totales en la 10; columna Q = importe total.
        $tipos = array_map(fn ($i) => $h->getCell("B$i")->getValue(), range(6, 9));
        $nc = array_search('07', $tipos, true) + 6;
        $this->assertEquals(-23.6, $h->getCell("Q$nc")->getValue());
        $this->assertSame('Totales', $h->getCell('A10')->getValue());
        $this->assertEqualsWithDelta(153.4, $h->getCell('Q10')->getCalculatedValue(), 0.001); // igual que el reporte en pantalla
        $this->assertEqualsWithDelta(23.4, $h->getCell('N10')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(153.4, $libro->getSheetByName('Resumen')->getCell('K'.($libro->getSheetByName('Resumen')->getHighestDataRow() - 2))->getCalculatedValue(), 0.001);
        @unlink($tmp);

        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($v)->get("/api/v1/reportes/registro-ventas/excel?desde=$hoy&hasta=$hoy")->assertForbidden();
    }

    public function test_permisos(): void
    {
        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $hoy = now('America/Lima')->toDateString();
        $this->actingAs($v)->getJson('/api/v1/dashboard')->assertOk();
        $this->actingAs($v)->getJson("/api/v1/reportes/ventas?desde=$hoy&hasta=$hoy&agrupar=dia")->assertForbidden();
    }
}
