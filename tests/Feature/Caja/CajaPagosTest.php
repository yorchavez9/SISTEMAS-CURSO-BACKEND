<?php

namespace Tests\Feature\Caja;

use App\Models\Caja;
use App\Models\Cliente;
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

class CajaPagosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $vendedor;
    private Serie $f001;
    private Serie $b001;
    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->vendedor = User::factory()->create();
        $this->vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 14]);
        $this->f001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '01', 'serie' => 'F001', 'correlativo_actual' => 0, 'api_serie_id' => 47]);
        $this->b001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '03', 'serie' => 'B001', 'correlativo_actual' => 0, 'api_serie_id' => 48]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);
    }

    private function abrirCaja(?User $u = null, float $monto = 100): array
    {
        $cajas = $this->actingAs($u ?? $this->vendedor)->getJson('/api/v1/caja/estado')->assertOk()->json('data.cajas');

        return $this->actingAs($u ?? $this->vendedor)->postJson('/api/v1/caja/abrir', ['caja_id' => $cajas[0]['id'], 'monto_apertura' => $monto])->assertCreated()->json('data');
    }

    private function venta(array $o = []): array
    {
        return $o + ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '01', 'serie_id' => $this->f001->id, 'cliente_id' => $this->cliente->id,
            'items' => [['descripcion' => 'Laptop', 'cantidad' => 1, 'precio_unitario' => 118, 'tip_afe_igv' => '10']]];
    }

    public function test_apertura_crea_caja_principal_y_es_unica(): void
    {
        $s = $this->abrirCaja();
        $this->assertSame('Caja principal', $s['caja']['nombre']);
        $this->assertSame('100.00', $s['resumen']['efectivo_esperado']);
        $this->actingAs($this->vendedor)->postJson('/api/v1/caja/abrir', ['caja_id' => $s['caja']['id'], 'monto_apertura' => 0])->assertStatus(422);

        $otro = User::factory()->create();
        $otro->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($otro)->postJson('/api/v1/caja/abrir', ['caja_id' => $s['caja']['id'], 'monto_apertura' => 0])
            ->assertStatus(422)->assertJsonPath('errors.caja_id.0', 'Esta caja ya está abierta por otro usuario.');
    }

    public function test_venta_contado_con_pago_mixto_y_vuelto(): void
    {
        $this->abrirCaja();
        // Total 118: 100 en efectivo (recibe 150 → vuelto 50) + 18 por Yape
        $r = $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['pagos' => [
            ['metodo' => 'efectivo', 'monto' => 100, 'monto_recibido' => 150],
            ['metodo' => 'yape', 'monto' => 18, 'referencia' => 'OP-123456'],
        ]]))->assertCreated();

        $v = Venta::withoutGlobalScopes()->findOrFail($r->json('data.venta_id'));
        $this->assertSame('pagado', $v->estado_pago);
        $this->assertSame('0.00', (string) $v->saldo);
        $this->assertSame('50.00', (string) $v->pagos()->where('metodo', 'efectivo')->value('vuelto'));

        $est = $this->actingAs($this->vendedor)->getJson('/api/v1/caja/estado')->json('data.sesion.resumen');
        $this->assertSame('200.00', $est['efectivo_esperado']); // 100 fondo + 100 efectivo (el vuelto no queda en caja)
        $this->assertSame('18.00', collect($est['por_metodo'])->firstWhere('metodo', 'yape')['neto']);
    }

    public function test_pagos_que_no_cubren_el_total_no_registran_nada(): void
    {
        $this->abrirCaja();
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['pagos' => [['metodo' => 'efectivo', 'monto' => 100]]]))
            ->assertStatus(422)->assertJsonValidationErrors('pagos');
        $this->assertSame(0, Venta::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->f001->fresh()->correlativo_actual); // transacción revertida: no se consumió número

        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['pagos' => [['metodo' => 'tarjeta_credito', 'monto' => 118, 'monto_recibido' => 120]]]))
            ->assertStatus(422)->assertJsonValidationErrors('pagos.0.monto_recibido');
    }

    public function test_pago_sin_caja_abierta(): void
    {
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['pagos' => [['metodo' => 'efectivo', 'monto' => 118]]]))
            ->assertStatus(422)->assertJsonValidationErrors('caja');
    }

    public function test_credito_cobranza_por_cuotas_y_cuentas_por_cobrar(): void
    {
        $this->abrirCaja();
        $hoy = now('America/Lima');
        $r = $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta([
            'forma_pago' => 'credito', 'items' => [['descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 1180, 'tip_afe_igv' => '10']],
            'cuotas' => [['monto' => 590, 'fecha_vencimiento' => $hoy->copy()->addMonth()->toDateString()], ['monto' => 590, 'fecha_vencimiento' => $hoy->copy()->addMonths(2)->toDateString()]],
        ]))->assertCreated();
        $id = $r->json('data.venta_id');

        $cxc = $this->actingAs($this->vendedor)->getJson('/api/v1/cuentas-por-cobrar')->assertOk()->json('data');
        $this->assertEquals(1180, $cxc['total']);
        $this->assertSame('al_dia', $cxc['filas'][0]['tramo']);

        $this->actingAs($this->vendedor)->postJson("/api/v1/ventas/$id/pagos", ['pagos' => [['metodo' => 'transferencia', 'monto' => 2000]]])
            ->assertStatus(422)->assertJsonValidationErrors('pagos');
        $v = $this->actingAs($this->vendedor)->postJson("/api/v1/ventas/$id/pagos", ['pagos' => [['metodo' => 'transferencia', 'monto' => 700, 'referencia' => 'OP 9']]])
            ->assertCreated()->json('data');
        $this->assertSame('parcial', $v['estado_pago']);
        $this->assertEquals([590, 110], array_column($v['cuotas'], 'pagado')); // se aplica a la cuota más antigua primero
        $this->assertEquals(480, $v['saldo']);

        // Anular el cobro restituye el saldo y registra el egreso en caja
        $pagoId = $v['pagos'][0]['id'];
        $v = $this->actingAs($this->vendedor)->postJson("/api/v1/pagos/$pagoId/anular", ['motivo' => 'Transferencia no confirmada'])->assertOk()->json('data');
        $this->assertEquals(1180, $v['saldo']);
        $this->assertSame('pendiente', $v['estado_pago']);
        $this->assertSame('0.00', collect($this->actingAs($this->vendedor)->getJson('/api/v1/caja/estado')->json('data.sesion.resumen.por_metodo'))->firstWhere('metodo', 'transferencia')['neto']);
    }

    public function test_credito_no_admite_pagos_en_la_venta(): void
    {
        $this->abrirCaja();
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['forma_pago' => 'credito', 'pagos' => [['metodo' => 'efectivo', 'monto' => 10]],
            'cuotas' => [['monto' => 118, 'fecha_vencimiento' => now('America/Lima')->addMonth()->toDateString()]]]))->assertStatus(422)->assertJsonValidationErrors('pagos');
    }

    public function test_movimientos_y_cierre_con_diferencia(): void
    {
        $this->abrirCaja(monto: 200);
        $this->actingAs($this->vendedor)->postJson('/api/v1/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'gasto', 'metodo' => 'efectivo', 'monto' => 500, 'descripcion' => 'Compra de útiles'])
            ->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->actingAs($this->vendedor)->postJson('/api/v1/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'gasto', 'metodo' => 'efectivo', 'monto' => 30.5, 'descripcion' => 'Compra de útiles'])->assertCreated();

        $s = $this->actingAs($this->vendedor)->postJson('/api/v1/caja/cerrar', ['efectivo_contado' => 169])->assertOk()->json('data');
        $this->assertSame('cerrada', $s['estado']);
        $this->assertSame('169.50', $s['resumen']['efectivo_esperado']);
        $this->assertEquals(-0.5, $s['diferencia']); // faltante de S/ 0.50
        $this->assertNull(Caja::query()->withoutGlobalScopes()->first()->sesionAbierta);

        // Tras cerrar ya se puede abrir de nuevo
        $this->abrirCaja();
    }

    public function test_no_se_anula_pago_de_caja_cerrada(): void
    {
        $this->abrirCaja();
        $r = $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['pagos' => [['metodo' => 'efectivo', 'monto' => 118]]]))->assertCreated();
        $pago = Venta::withoutGlobalScopes()->find($r->json('data.venta_id'))->pagos()->first();
        $this->actingAs($this->vendedor)->postJson('/api/v1/caja/cerrar', ['efectivo_contado' => 218])->assertOk();
        $this->abrirCaja();
        $this->actingAs($this->vendedor)->postJson("/api/v1/pagos/{$pago->id}/anular", ['motivo' => 'Error de cobro'])
            ->assertStatus(422)->assertJsonValidationErrors('pago');
    }

    public function test_contador_ve_pero_no_opera_caja(): void
    {
        $contador = User::factory()->create();
        $contador->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($contador)->getJson('/api/v1/caja/sesiones')->assertOk();
        $this->actingAs($contador)->getJson('/api/v1/caja/estado')->assertForbidden();
        $this->actingAs($contador)->getJson('/api/v1/cuentas-por-cobrar')->assertOk();
    }
}
