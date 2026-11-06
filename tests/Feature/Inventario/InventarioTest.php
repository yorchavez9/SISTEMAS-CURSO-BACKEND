<?php

namespace Tests\Feature\Inventario;

use App\Models\Almacen;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventarioTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Establecimiento $est;
    private Producto $arroz;
    private Producto $servicio;
    private Serie $f001;

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
        $this->f001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $this->est->id, 'tipo_documento' => '01', 'serie' => 'F001', 'api_serie_id' => 47]);
        $this->arroz = Producto::create(['empresa_id' => $this->empresa->id, 'codigo' => 'ARZ', 'descripcion' => 'Arroz x kg', 'unidad' => 'KGM', 'precio_venta' => 5.9, 'costo' => 4, 'tip_afe_igv' => '10', 'stock_minimo' => 5]);
        $this->servicio = Producto::create(['empresa_id' => $this->empresa->id, 'tipo' => 'servicio', 'controla_stock' => false, 'codigo' => 'SRV', 'descripcion' => 'Delivery', 'unidad' => 'ZZ', 'precio_venta' => 10, 'tip_afe_igv' => '10']);
    }

    private function almacen(): array
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/almacenes')->assertOk()->json('data.0');
    }

    private function entrada(int $almacenId, float $cant, float $costo): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'entrada', 'almacen_id' => $almacenId, 'documento' => 'F001-123',
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => $cant, 'costo_unitario' => $costo]]])->assertCreated();
    }

    private function stock(int $almacenId): Stock
    {
        return Stock::withoutGlobalScopes()->where('almacen_id', $almacenId)->where('producto_id', $this->arroz->id)->firstOrFail();
    }

    public function test_almacen_principal_automatico_y_costo_promedio_ponderado(): void
    {
        $a = $this->almacen();
        $this->assertSame('Almacén principal', $a['nombre']);
        $this->entrada($a['id'], 100, 4.00);   // 100 × 4.00
        $this->entrada($a['id'], 50, 4.60);    // 50 × 4.60 → promedio (400 + 230) / 150 = 4.20
        $s = $this->stock($a['id']);
        $this->assertSame('150.0000', (string) $s->cantidad);
        $this->assertSame('4.200000', (string) $s->costo_promedio);

        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'salida', 'almacen_id' => $a['id'], 'observacion' => 'Merma',
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => 30]]])->assertCreated();
        $k = $this->actingAs($this->admin)->getJson("/api/v1/inventario/kardex/{$this->arroz->id}")->assertOk()->json('data.movimientos');
        $this->assertCount(3, $k);
        $this->assertEquals(['salida', 30, 4.2, 126, 120], [$k[2]['sentido'], $k[2]['cantidad'], $k[2]['costo_unitario'], $k[2]['valor'], $k[2]['saldo_cantidad']]);
    }

    public function test_no_permite_stock_negativo_ni_servicios(): void
    {
        $a = $this->almacen();
        $this->entrada($a['id'], 10, 4);
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'salida', 'almacen_id' => $a['id'], 'observacion' => 'x',
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => 11]]])
            ->assertStatus(422)->assertJsonPath('errors.items.0', 'Stock insuficiente de Arroz x kg en Almacén principal: disponible 10, requerido 11.');
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'entrada', 'almacen_id' => $a['id'],
            'items' => [['producto_id' => $this->servicio->id, 'cantidad' => 1, 'costo_unitario' => 1]]])->assertStatus(422);
    }

    public function test_ajuste_a_inventario_fisico(): void
    {
        $a = $this->almacen();
        $this->entrada($a['id'], 20, 4);
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'ajuste', 'almacen_id' => $a['id'], 'observacion' => 'Conteo mensual',
            'items' => [['producto_id' => $this->arroz->id, 'cantidad_fisica' => 17]]])->assertCreated();
        $this->assertSame('17.0000', (string) $this->stock($a['id'])->cantidad);
        $this->assertSame('ajuste_salida', MovimientoInventario::withoutGlobalScopes()->latest('id')->value('tipo'));
        // Sin diferencia no genera movimiento
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'ajuste', 'almacen_id' => $a['id'], 'observacion' => 'Conteo',
            'items' => [['producto_id' => $this->arroz->id, 'cantidad_fisica' => 17]]])->assertCreated()->assertJsonPath('data.movimientos', 0);
    }

    public function test_transferencia_conserva_costo(): void
    {
        $a = $this->almacen();
        $b = $this->actingAs($this->admin)->postJson('/api/v1/almacenes', ['establecimiento_id' => $this->est->id, 'nombre' => 'Depósito'])->assertCreated()->json('data');
        $this->entrada($a['id'], 40, 4.5);
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/transferencias', ['origen_id' => $a['id'], 'destino_id' => $b['id'],
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => 15]]])->assertCreated();
        $this->assertSame('25.0000', (string) $this->stock($a['id'])->cantidad);
        $this->assertSame('15.0000', (string) $this->stock($b['id'])->cantidad);
        $this->assertSame('4.500000', (string) $this->stock($b['id'])->costo_promedio);
    }

    public function test_la_venta_descuenta_stock_y_sin_stock_no_se_registra(): void
    {
        $a = $this->almacen();
        $this->entrada($a['id'], 3, 4);
        $cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);
        $venta = fn (float $cant) => ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '01', 'serie_id' => $this->f001->id, 'cliente_id' => $cliente->id,
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => $cant], ['producto_id' => $this->servicio->id, 'cantidad' => 1]]];

        $this->actingAs($this->admin)->postJson('/api/v1/ventas', $venta(5))->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(0, $this->f001->fresh()->correlativo_actual); // nada se registró

        $this->actingAs($this->admin)->postJson('/api/v1/ventas', $venta(2))->assertCreated();
        $this->assertSame('1.0000', (string) $this->stock($a['id'])->cantidad);
        $mov = MovimientoInventario::withoutGlobalScopes()->where('tipo', 'venta')->sole();
        $this->assertSame('F001-1', $mov->documento);
        $this->assertSame(0, MovimientoInventario::withoutGlobalScopes()->where('producto_id', $this->servicio->id)->count()); // el servicio no mueve stock

        $st = $this->actingAs($this->admin)->getJson("/api/v1/inventario/stock?almacen_id={$a['id']}&bajo_minimo=1")->assertOk();
        $this->assertSame(1, $st->json('meta.total'));
        $this->assertTrue($st->json('data.0.bajo_minimo'));
    }

    public function test_empresa_puede_permitir_vender_sin_stock(): void
    {
        $a = $this->almacen();
        $cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}", ['permitir_stock_negativo' => true])
            ->assertOk()->assertJsonPath('data.permitir_stock_negativo', true);

        $this->actingAs($this->admin)->postJson('/api/v1/ventas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '01', 'serie_id' => $this->f001->id,
            'cliente_id' => $cliente->id, 'items' => [['producto_id' => $this->arroz->id, 'cantidad' => 4]]])->assertCreated();
        $this->assertSame('-4.0000', (string) $this->stock($a['id'])->cantidad); // el kardex refleja la deuda de stock
        // Los movimientos manuales siguen sin permitir negativos.
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'salida', 'almacen_id' => $a['id'], 'observacion' => 'x',
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => 1]]])->assertStatus(422);
    }

    public function test_almacen_de_otra_empresa_no_es_accesible(): void
    {
        $otra = Empresa::factory()->create();
        $estOtra = Establecimiento::create(['empresa_id' => $otra->id, 'nombre' => 'X', 'cod_local' => '0000']);
        $ajeno = Almacen::create(['empresa_id' => $otra->id, 'establecimiento_id' => $estOtra->id, 'nombre' => 'Ajeno']);
        $this->actingAs($this->admin)->getJson("/api/v1/inventario/stock?almacen_id={$ajeno->id}")->assertNotFound();
        $this->actingAs($this->admin)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'entrada', 'almacen_id' => $ajeno->id,
            'items' => [['producto_id' => $this->arroz->id, 'cantidad' => 1, 'costo_unitario' => 1]]])->assertNotFound();
    }

    public function test_vendedor_ve_pero_no_mueve_inventario(): void
    {
        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $a = $this->almacen();
        $this->actingAs($v)->getJson("/api/v1/inventario/stock?almacen_id={$a['id']}")->assertOk();
        $this->actingAs($v)->postJson('/api/v1/inventario/movimientos', ['tipo' => 'entrada', 'almacen_id' => $a['id'], 'items' => []])->assertForbidden();
    }
}
