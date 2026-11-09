<?php

namespace Tests\Feature\Liquidaciones;

use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\LiquidacionCompra;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\ProcesoSunat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiquidacionesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Serie $l002;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0001', 'api_sucursal_id' => 14]);
        $this->l002 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '04', 'serie' => 'L002', 'api_serie_id' => 280]);
    }

    private function body(array $o = []): array
    {
        return $o + ['idempotency_key' => (string) Str::uuid(), 'serie_id' => $this->l002->id,
            'vendedor' => ['tipo_doc' => '1', 'num_doc' => '12345678', 'nombre' => 'JUAN PEREZ', 'direccion' => 'CASERIO S/N', 'ubigeo' => '150101'],
            'lugar' => ['direccion' => 'AV. ACOPIO 500', 'ubigeo' => '150101'],
            'items' => [['descripcion' => 'Cafe', 'cantidad' => 100, 'valor_unitario' => 10, 'unidad' => 'KGM', 'cod_producto_sunat' => '10101500', 'tip_afe_igv' => '10']]];
    }

    /** Respuesta real de POST /liquidaciones-compra (beta, L002-1). */
    private function api(string $estado, int $correlativo = 1): array
    {
        return ['estado' => 'exito', 'datos' => ['id' => 9, 'serie' => 'L002', 'correlativo' => $correlativo,
            'totales' => ['gravadas' => 1000, 'igv' => 180, 'valor_venta' => 1000, 'sub_total' => 1180, 'porcentaje_renta' => 1.5, 'retencion_renta' => 15, 'neto_a_pagar' => 985],
            'sunat' => ['estado' => $estado, 'codigo' => $estado === 'aceptado' ? '0' : null, 'descripcion' => $estado === 'aceptado' ? 'Aceptada' : null, 'notas' => null],
            'estado_pago' => 'pendiente', 'monto_pagado' => 0]];
    }

    public function test_emite_con_payload_de_la_api_y_toma_los_totales_oficiales(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $this->body(['productos_agropecuarios' => true]))->assertCreated()
            ->assertJsonPath('data.numero', 'L002-1')->assertJsonPath('data.igv', 180)->assertJsonPath('data.estado', 'por_enviar');
        $c = LiquidacionCompra::withoutGlobalScopes()->sole();
        $p = $c->payload;
        $this->assertEquals(['L002', 1, '0501', true, '01', '01'], [$p['serie'], $p['correlativo'], $p['tipo_operacion'], $p['productos_agropecuarios'], $p['vendedor']['tipo_domicilio'], $p['lugar']['tipo']]);

        Http::fake([
            "{$this->base}/liquidaciones-compra" => Http::response($this->api('enviado'), 201),
            "{$this->base}/liquidaciones-compra/9" => Http::response($this->api('aceptado')),
        ]);
        app(ProcesoSunat::class)->emitirLiquidacion($c);
        $this->assertSame(['enviado', 9], [$c->fresh()->estado, (int) $c->fresh()->api_id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['vendedor']['num_doc'] === '12345678');

        $this->actingAs($this->admin)->getJson("/api/v1/liquidaciones/{$c->id}")->assertOk()
            ->assertJsonPath('data.estado', 'aceptado')->assertJsonPath('data.renta', 15)->assertJsonPath('data.neto_a_pagar', 985)->assertJsonPath('data.revertible', true);
    }

    public function test_exige_codigo_sunat_y_permite_corregir_un_envio_rechazado(): void
    {
        $sin = $this->body();
        unset($sin['items'][0]['cod_producto_sunat']);
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $sin)->assertUnprocessable()->assertJsonValidationErrors('items.0.cod_producto_sunat');

        // Liquidación antigua emitida sin código: la API la rechazó por validación.
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $this->body())->assertCreated();
        $c = LiquidacionCompra::withoutGlobalScopes()->sole();
        $items = [array_diff_key($c->items[0], ['cod_producto_sunat' => 1])];
        $c->update(['estado' => 'error', 'items' => $items, 'payload' => [...$c->payload, 'items' => $items]]);
        Http::fake(["{$this->base}/liquidaciones-compra" => Http::response($this->api('aceptado'), 201)]);

        $this->actingAs($this->admin)->postJson("/api/v1/liquidaciones/{$c->id}/reintentar")->assertUnprocessable()->assertJsonValidationErrors('codigos');
        Http::assertNothingSent();
        $this->actingAs($this->admin)->postJson("/api/v1/liquidaciones/{$c->id}/reintentar", ['codigos' => ['10101500']])->assertOk()->assertJsonPath('data.estado', 'aceptado');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['items'][0]['cod_producto_sunat'] === '10101500');
    }

    public function test_numero_ocupado_se_renumera(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $this->body())->assertCreated();
        $c = LiquidacionCompra::withoutGlobalScopes()->sole();
        Http::fake([
            "{$this->base}/liquidaciones-compra" => Http::sequence()->push(['estado' => 'error', 'mensaje' => 'Error', 'errores' => ['correlativo' => ['El correlativo ya existe.']]], 422)->push($this->api('aceptado', 6), 201),
            "{$this->base}/series" => Http::response(['estado' => 'exito', 'datos' => [['serie' => 'L002', 'correlativo' => 5]]]),
        ]);
        app(ProcesoSunat::class)->emitirLiquidacion($c);
        $this->assertSame(['L002-6', 'aceptado'], [$c->fresh()->numero(), $c->fresh()->estado]);
    }

    public function test_pagos_reversion_y_archivos(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $this->body())->assertCreated();
        $c = LiquidacionCompra::withoutGlobalScopes()->sole();
        $c->update(['estado' => 'aceptado', 'api_id' => 9]);
        Http::fake([
            "{$this->base}/liquidaciones-compra/9/pagos" => fn (Request $r) => Http::response($r->method() === 'POST' ? ['estado' => 'exito', 'datos' => []]
                : ['estado' => 'exito', 'datos' => ['pagos' => [['id' => 3, 'metodo' => 'efectivo', 'monto' => 500]], 'estado_pago' => 'parcial', 'monto_pagado' => 500, 'total_documento' => 985]]),
            "{$this->base}/liquidaciones-compra/9/cdr" => Http::response('PK', 200, ['Content-Type' => 'application/zip']),
            "{$this->base}/liquidaciones-compra/9/pdf*" => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
            "{$this->base}/liquidaciones-compra/9/reversion" => Http::response(['estado' => 'error', 'mensaje' => 'Tiene pagos registrados.'], 422),
        ]);
        $this->actingAs($this->admin)->postJson("/api/v1/liquidaciones/{$c->id}/pagos", ['metodo' => 'efectivo', 'monto' => 500, 'referencia' => 'OP-1'])
            ->assertCreated()->assertJsonPath('data.estado_pago', 'parcial');
        $this->assertSame(['parcial', '500.00'], [$c->fresh()->estado_pago, (string) $c->fresh()->monto_pagado]);

        $this->actingAs($this->admin)->get("/api/v1/liquidaciones/{$c->id}/archivos/cdr")->assertOk();
        $this->actingAs($this->admin)->get("/api/v1/liquidaciones/{$c->id}/archivos/pdf?formato=a5")->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/pdf') && $r['formato'] === 'a5');

        $this->actingAs($this->admin)->postJson("/api/v1/liquidaciones/{$c->id}/revertir", ['causal' => 'producto', 'motivo' => 'Error en el bien'])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->assertSame('error', $c->fresh()->reversion_estado);
    }

    public function test_validaciones_permisos_e_idempotencia(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $this->body(['vendedor' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'nombre' => 'X', 'direccion' => 'Y', 'ubigeo' => '150101']]))
            ->assertUnprocessable()->assertJsonValidationErrors('vendedor.tipo_doc');
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $this->body(['lugar' => ['direccion' => 'X', 'ubigeo' => '1']]))->assertUnprocessable()->assertJsonValidationErrors('lugar.ubigeo');
        $b = $this->body();
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $b)->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/liquidaciones', $b)->assertCreated();
        $this->assertSame(1, LiquidacionCompra::withoutGlobalScopes()->count());

        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($v)->getJson('/api/v1/liquidaciones')->assertForbidden();
        $c = User::factory()->create();
        $c->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($c)->getJson('/api/v1/liquidaciones')->assertOk();
        $this->actingAs($c)->postJson('/api/v1/liquidaciones', $this->body())->assertForbidden();
    }
}
