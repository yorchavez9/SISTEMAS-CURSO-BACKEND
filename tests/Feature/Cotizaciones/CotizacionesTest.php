<?php

namespace Tests\Feature\Cotizaciones;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CotizacionesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $vendedor;
    private Cliente $cliente;
    private Producto $servicio;
    private Serie $f001;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(); // las cotizaciones no llaman a la API
        $this->empresa = Empresa::factory()->create();
        $this->vendedor = User::factory()->create();
        $this->vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'P', 'cod_local' => '0000', 'api_sucursal_id' => 1]);
        $this->f001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '01', 'serie' => 'F001', 'api_serie_id' => 47]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT', 'email' => 'compras@sunat.gob.pe']);
        $this->servicio = Producto::create(['empresa_id' => $this->empresa->id, 'codigo' => 'S01', 'descripcion' => 'Consultoría', 'unidad' => 'ZZ', 'precio_venta' => 1180, 'tip_afe_igv' => '10', 'tipo' => 'servicio', 'controla_stock' => false]);
    }

    private function cotizar(array $o = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->vendedor)->postJson('/api/v1/cotizaciones', $o + [
            'cliente_id' => $this->cliente->id, 'fecha_validez' => now('America/Lima')->addDays(15)->toDateString(), 'observacion' => 'Precios incluyen IGV.',
            'items' => [['producto_id' => $this->servicio->id, 'cantidad' => 1], ['descripcion' => 'Viáticos', 'unidad' => 'ZZ', 'cantidad' => 2, 'precio_unitario' => 59, 'tip_afe_igv' => '10', 'descuento' => 10]],
        ]);
    }

    public function test_crea_con_el_motor_tributario_y_numeracion_correlativa(): void
    {
        $this->cotizar()->assertCreated()
            ->assertJsonPath('data.codigo', 'COT-000001')->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.cliente.email', 'compras@sunat.gob.pe')
            // 1000 + (100 − 10) de base; IGV 18 % = 196.20; total 1286.20
            ->assertJsonPath('data.totales.gravadas', 1090)->assertJsonPath('data.totales.igv', 196.2)->assertJsonPath('data.totales.total', 1286.2)
            ->assertJsonPath('data.totales.descuentos', 10);
        $this->cotizar()->assertCreated()->assertJsonPath('data.codigo', 'COT-000002');
        Http::assertNothingSent();

        // Prospecto sin cliente registrado
        $this->cotizar(['cliente_id' => null, 'cliente_nombre' => 'Bodega Don Lucho'])->assertCreated()->assertJsonPath('data.cliente.razon_social', 'Bodega Don Lucho');
        $this->cotizar(['cliente_id' => null])->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->cotizar(['fecha_validez' => now('America/Lima')->subDay()->toDateString()])->assertStatus(422);
    }

    public function test_estados_y_vencimiento_calculado(): void
    {
        $id = $this->cotizar()->json('data.id');
        $this->actingAs($this->vendedor)->putJson("/api/v1/cotizaciones/$id/estado", ['estado' => 'enviada'])->assertOk()->assertJsonPath('data.estado', 'enviada');
        $this->actingAs($this->vendedor)->putJson("/api/v1/cotizaciones/$id/estado", ['estado' => 'convertida'])->assertStatus(422);

        Cotizacion::withoutGlobalScopes()->whereKey($id)->update(['fecha_validez' => now('America/Lima')->subDay()->toDateString()]);
        $this->actingAs($this->vendedor)->getJson("/api/v1/cotizaciones/$id")->assertJsonPath('data.estado', 'vencida')->assertJsonPath('data.convertible', false);
        $this->actingAs($this->vendedor)->putJson("/api/v1/cotizaciones/$id/estado", ['estado' => 'aceptada'])->assertStatus(422);
        $this->actingAs($this->vendedor)->getJson('/api/v1/cotizaciones?estado=vencida')->assertJsonPath('meta.total', 1);
        $this->actingAs($this->vendedor)->getJson('/api/v1/cotizaciones?estado=enviada')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->vendedor)->putJson("/api/v1/cotizaciones/$id/estado", ['estado' => 'rechazada'])->assertOk(); // una vencida sí se puede rechazar
    }

    public function test_convertir_en_venta_marca_la_cotizacion_una_sola_vez(): void
    {
        $id = $this->cotizar()->json('data.id');
        $venta = fn () => $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '01',
            'serie_id' => $this->f001->id, 'cliente_id' => $this->cliente->id, 'cotizacion_id' => $id, 'items' => [['producto_id' => $this->servicio->id, 'cantidad' => 1]]]);
        $venta()->assertCreated();
        $c = Cotizacion::withoutGlobalScopes()->find($id);
        $this->assertSame('convertida', $c->estado);
        $this->assertSame($c->venta_id, Venta::withoutGlobalScopes()->where('cotizacion_id', $id)->value('id'));
        $this->actingAs($this->vendedor)->getJson("/api/v1/cotizaciones/$id")->assertJsonPath('data.venta.numero', 'F001-1');

        $venta()->assertStatus(422)->assertJsonValidationErrors('cotizacion_id'); // no se convierte dos veces
        $this->assertSame(1, Venta::withoutGlobalScopes()->count());
    }

    public function test_permisos_y_aislamiento(): void
    {
        $id = $this->cotizar()->json('data.id');
        $contador = User::factory()->create();
        $contador->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($contador)->getJson('/api/v1/cotizaciones')->assertForbidden();

        $otra = Empresa::factory()->create();
        $this->vendedor->asignarEnEmpresa($otra, 'vendedor');
        $this->actingAs($this->vendedor)->withHeader('X-Empresa-Id', (string) $otra->id)->getJson("/api/v1/cotizaciones/$id")->assertNotFound();
    }
}
