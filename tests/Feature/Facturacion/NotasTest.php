<?php

namespace Tests\Feature\Facturacion;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\Stock;
use App\Models\User;
use App\Models\Venta;
use App\Modules\Facturacion\Jobs\EmitirComprobanteJob;
use App\Modules\Facturacion\Services\EmisionService;
use App\Modules\Inventario\Services\InventarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotasTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Establecimiento $est;
    private Serie $f001;
    private Serie $fc01;
    private Serie $fd01;
    private Serie $bc01;
    private Cliente $cliente;
    private Producto $mouse;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $this->est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 14]);
        $s = fn ($t, $n) => Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $this->est->id, 'tipo_documento' => $t, 'serie' => $n, 'api_serie_id' => crc32($n)]);
        $this->f001 = $s('01', 'F001');
        $this->fc01 = $s('07', 'FC01');
        $this->fd01 = $s('08', 'FD01');
        $this->bc01 = $s('07', 'BC01');
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);
        $this->mouse = Producto::create(['empresa_id' => $this->empresa->id, 'codigo' => 'P001', 'descripcion' => 'MOUSE', 'unidad' => 'NIU', 'precio_venta' => 35.4, 'costo' => 20, 'tip_afe_igv' => '10']);
        $alm = app(InventarioService::class)->almacenPrincipal($this->empresa->id, $this->est->id);
        app(InventarioService::class)->registrar($this->empresa, $this->admin, $alm, 'entrada', [['producto_id' => $this->mouse->id, 'cantidad' => 10, 'costo_unitario' => 20]], 'INI', null);
    }

    /** Factura aceptada: 3 mouse (106.20) + servicio 118 = 224.20 */
    private function facturaAceptada(array $pagos = []): Comprobante
    {
        if ($pagos) {
            $cajas = $this->actingAs($this->admin)->getJson('/api/v1/caja/estado')->json('data.cajas');
            $this->actingAs($this->admin)->postJson('/api/v1/caja/abrir', ['caja_id' => $cajas[0]['id'], 'monto_apertura' => 500])->assertCreated();
        }
        $this->actingAs($this->admin)->postJson('/api/v1/ventas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '01', 'serie_id' => $this->f001->id,
            'cliente_id' => $this->cliente->id, 'pagos' => $pagos ?: null,
            'items' => [['producto_id' => $this->mouse->id, 'cantidad' => 3], ['descripcion' => 'Instalación', 'cantidad' => 1, 'precio_unitario' => 118, 'tip_afe_igv' => '10']]])->assertCreated();
        $c = Comprobante::withoutGlobalScopes()->where('tipo_documento', '01')->latest('id')->first();
        $c->update(['estado' => 'aceptado', 'api_id' => 900]);

        return $c;
    }

    private function nota(Comprobante $ref, array $o): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/notas', $o + ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '07',
            'comprobante_id' => $ref->id, 'serie_id' => $this->fc01->id]);
    }

    private function stockMouse(): string
    {
        return (string) Stock::withoutGlobalScopes()->where('producto_id', $this->mouse->id)->value('cantidad');
    }

    public function test_nc_anulacion_total_con_payload_postman_y_devolucion_de_stock(): void
    {
        $ref = $this->facturaAceptada();
        $this->assertSame('7.0000', $this->stockMouse());
        $this->nota($ref, ['cod_motivo' => '01', 'des_motivo' => 'Anulación de la operación'])->assertCreated()->assertJsonPath('data.numero', 'FC01-1');

        $nc = Comprobante::withoutGlobalScopes()->where('tipo_documento', '07')->sole();
        $this->assertSame('224.20', (string) $nc->total);
        $p = $nc->payload;
        // Estructura del ejemplo "NC - Anulación total de factura" (NC-01, aceptado en la Etapa 1)
        $this->assertEquals(['01', 'F001', '1', '01', 'Anulación de la operación'], [$p['doc_afectado_tipo'], $p['doc_afectado_serie'], $p['doc_afectado_correlativo'], $p['cod_motivo'], $p['des_motivo']]);
        $this->assertCount(2, $p['items']);
        $this->assertSame('10.0000', $this->stockMouse());                         // los 3 mouse volvieron
        $v = Venta::withoutGlobalScopes()->find($ref->venta_id);
        $this->assertSame('anulada', $v->estado);
        $this->assertSame('0.00', (string) $v->saldo);                             // ya no hay nada que cobrar
        Queue::assertPushed(EmitirComprobanteJob::class, fn ($j) => $j->comprobanteId === $nc->id);

        // Una segunda NC de anulación ya no es posible
        $this->nota($ref, ['cod_motivo' => '01', 'des_motivo' => 'Otra anulación'])->assertStatus(422)->assertJsonValidationErrors('cod_motivo');
    }

    public function test_nc_devolucion_por_item_con_limites(): void
    {
        $ref = $this->facturaAceptada();
        $linea = Venta::withoutGlobalScopes()->find($ref->venta_id)->detalles()->where('producto_id', $this->mouse->id)->first();

        $this->nota($ref, ['cod_motivo' => '07', 'des_motivo' => 'Devolución de 2 mouse', 'items' => [['venta_detalle_id' => $linea->id, 'cantidad' => 2]]])->assertCreated();
        $this->assertSame('9.0000', $this->stockMouse());
        $this->assertSame('70.80', (string) Comprobante::withoutGlobalScopes()->where('tipo_documento', '07')->value('total'));

        $r = $this->nota($ref, ['cod_motivo' => '07', 'des_motivo' => 'Devolución de 2 más', 'items' => [['venta_detalle_id' => $linea->id, 'cantidad' => 2]]])->assertStatus(422);
        $this->assertSame('Solo se pueden devolver 1 de MOUSE.', $r->json('errors')['items.0.cantidad'][0]);
        $this->assertSame('153.40', (string) Venta::withoutGlobalScopes()->find($ref->venta_id)->saldo); // 224.20 − 70.80
    }

    public function test_nc_por_monto_no_supera_lo_disponible(): void
    {
        $ref = $this->facturaAceptada();
        $this->nota($ref, ['cod_motivo' => '04', 'des_motivo' => 'Descuento por volumen', 'items' => [['descripcion' => 'Descuento', 'precio_unitario' => 200]]])->assertCreated();
        $this->nota($ref, ['cod_motivo' => '04', 'des_motivo' => 'Otro descuento', 'items' => [['descripcion' => 'Descuento', 'precio_unitario' => 30]]])
            ->assertStatus(422)->assertJsonPath('errors.items.0', 'Las notas de crédito no pueden superar el total de F001-1: disponible 24.20 (SUNAT 3286).');
        $this->assertSame('7.0000', $this->stockMouse()); // el descuento no mueve stock
    }

    public function test_nc_con_reembolso_en_caja(): void
    {
        $ref = $this->facturaAceptada([['metodo' => 'efectivo', 'monto' => 224.2]]);
        $this->nota($ref, ['cod_motivo' => '06', 'des_motivo' => 'Devolución total', 'reembolso' => ['metodo' => 'efectivo']])->assertCreated();

        $v = Venta::withoutGlobalScopes()->find($ref->venta_id);
        $this->assertSame('0.00', (string) $v->monto_pagado);
        $this->assertSame('0.00', (string) $v->saldo);
        $re = Pago::withoutGlobalScopes()->where('tipo', 'reembolso')->sole();
        $this->assertSame('224.20', (string) $re->monto);
        $res = $this->actingAs($this->admin)->getJson('/api/v1/caja/estado')->json('data.sesion.resumen');
        $this->assertSame('500.00', $res['efectivo_esperado']); // 500 fondo + 224.20 venta − 224.20 reembolso
    }

    public function test_nd_aumenta_la_cuenta_por_cobrar(): void
    {
        $ref = $this->facturaAceptada();
        $this->nota($ref, ['tipo_documento' => '08', 'serie_id' => $this->fd01->id, 'cod_motivo' => '01', 'des_motivo' => 'Intereses por mora',
            'items' => [['descripcion' => 'Interés por pago fuera de plazo', 'precio_unitario' => 23.6]]])->assertCreated()->assertJsonPath('data.numero', 'FD01-1');
        $this->assertSame('247.80', (string) Venta::withoutGlobalScopes()->find($ref->venta_id)->saldo);
        $cxc = $this->actingAs($this->admin)->getJson('/api/v1/cuentas-por-cobrar')->json('data');
        $this->assertEquals(247.8, $cxc['total']);
    }

    public function test_validaciones(): void
    {
        $ref = $this->facturaAceptada();
        $ref->update(['estado' => 'enviado']);
        $this->nota($ref, ['cod_motivo' => '01', 'des_motivo' => 'Anulación'])->assertStatus(422)->assertJsonValidationErrors('comprobante_id');
        $ref->update(['estado' => 'aceptado']);
        // Serie de boleta (BC01) para nota de factura
        $this->nota($ref, ['serie_id' => $this->bc01->id, 'cod_motivo' => '01', 'des_motivo' => 'Anulación'])->assertStatus(422)->assertJsonValidationErrors('serie_id');
        // Motivo de ND en una NC
        $this->nota($ref, ['cod_motivo' => '13', 'des_motivo' => 'Ajuste de cuotas'])->assertStatus(422)->assertJsonValidationErrors('cod_motivo');
        $this->assertSame(0, $this->fc01->fresh()->correlativo_actual);
    }

    public function test_idempotencia(): void
    {
        $ref = $this->facturaAceptada();
        $d = ['idempotency_key' => (string) Str::uuid(), 'cod_motivo' => '04', 'des_motivo' => 'Descuento', 'items' => [['descripcion' => 'Dscto', 'precio_unitario' => 10]]];
        $this->nota($ref, $d)->assertCreated();
        $this->nota($ref, $d)->assertOk();
        $this->assertSame(1, Comprobante::withoutGlobalScopes()->where('tipo_documento', '07')->count());
    }

    public function test_emision_a_notas_credito(): void
    {
        $ref = $this->facturaAceptada();
        $this->nota($ref, ['cod_motivo' => '01', 'des_motivo' => 'Anulación de la operación'])->assertCreated();
        $nc = Comprobante::withoutGlobalScopes()->where('tipo_documento', '07')->sole();
        $base = rtrim(config('services.sunat_api.base_url'), '/');
        Http::fake(["$base/notas-credito" => Http::response(['estado' => 'exito', 'mensaje' => 'Nota de crédito creada y encolada para envío a SUNAT.',
            'datos' => ['id' => 26, 'serie' => 'FC01', 'correlativo' => 1, 'totales' => ['total' => 224.2], 'sunat' => ['estado' => 'enviado']]], 201)]);
        $this->assertSame(EmisionService::RESULTADO_ENVIADO, app(EmisionService::class)->emitir($nc));
        Http::assertSent(fn (Request $r) => $r->url() === "$base/notas-credito" && $r['doc_afectado_serie'] === 'F001');
        $this->assertFalse($nc->fresh()->diferencia_totales);
    }
}
