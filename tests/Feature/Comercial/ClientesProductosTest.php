<?php

namespace Tests\Feature\Comercial;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Producto;
use App\Models\User;
use App\Modules\Clientes\DocumentoIdentidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientesProductosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456',
            'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
    }

    // ---------------- Clientes ----------------

    public function test_ruc_con_digito_verificador_sunat(): void
    {
        $this->assertNull(DocumentoIdentidad::errorRuc('20131312955'));   // RUC de SUNAT
        $this->assertNull(DocumentoIdentidad::errorRuc('20161515648'));
        $this->assertNotNull(DocumentoIdentidad::errorRuc('20512345678')); // RUC de ejemplo de Postman: dígito inválido
        $this->assertNotNull(DocumentoIdentidad::errorRuc('30131312955'));
        $this->assertNull(DocumentoIdentidad::error('1', '72243561'));
        $this->assertNotNull(DocumentoIdentidad::error('1', '7224356'));
        $this->assertNull(DocumentoIdentidad::error('4', 'CE001234'));
    }

    public function test_crud_de_cliente_con_validaciones(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/clientes', ['tipo_documento' => '6', 'numero_documento' => '20512345678', 'razon_social' => 'EMPRESA X SAC'])
            ->assertStatus(422)->assertJsonValidationErrors('numero_documento');

        $id = $this->actingAs($this->admin)->postJson('/api/v1/clientes', [
            'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA',
            'direccion' => 'AV. GARCILASO DE LA VEGA 1472', 'email' => 'facturas@demo.pe',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/clientes', ['tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'Dup'])
            ->assertStatus(422)->assertJsonValidationErrors('numero_documento');

        $this->actingAs($this->admin)->putJson("/api/v1/clientes/$id", ['telefono' => '+51 999 111 222'])->assertOk()->assertJsonPath('data.telefono', '+51 999 111 222');
        $this->actingAs($this->admin)->getJson('/api/v1/clientes?q=SUPERINT')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->admin)->deleteJson("/api/v1/clientes/$id")->assertOk();
        $this->assertSoftDeleted('clientes', ['id' => $id]);

        // Un documento de un cliente eliminado no se duplica: se pide restaurarlo.
        $this->actingAs($this->admin)->postJson('/api/v1/clientes', ['tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'Otra'])
            ->assertStatus(422)->assertJsonPath('errors.numero_documento.0', 'Existe un cliente eliminado con este documento. Restáuralo en lugar de crear uno nuevo.');
    }

    public function test_paraComprobante_usa_la_forma_inline_de_la_api(): void
    {
        $c = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '1', 'numero_documento' => '72243561', 'razon_social' => 'CHAVEZ HUINCHO JORGE']);
        $this->assertSame(['tipo_doc' => '1', 'num_doc' => '72243561', 'razon_social' => 'CHAVEZ HUINCHO JORGE'], $c->paraComprobante());
    }

    public function test_consulta_documento_en_la_api_con_la_respuesta_real(): void
    {
        $base = rtrim(config('services.sunat_api.base_url'), '/');
        // Forma real capturada en la Etapa 1 (01C.08)
        Http::fake(["$base/buscar-documento*" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
            'tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUPERINTENDENCIA NACIONAL', 'direccion' => 'AV. GARCILASO',
            'estado' => 'ACTIVO', 'condicion' => 'NO HABIDO', 'source' => 'sunat',
        ]])]);

        $this->actingAs($this->admin)->getJson('/api/v1/clientes/consultar-documento?tipo=6&numero=20131312955')
            ->assertOk()->assertJsonPath('data.existe', false)->assertJsonPath('data.cliente.razon_social', 'SUPERINTENDENCIA NACIONAL')
            ->assertJsonPath('data.fuente', 'sunat')->assertJsonPath('data.advertencia', 'El contribuyente figura en SUNAT como ACTIVO / NO HABIDO.');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'buscar-documento?tipo=6&numero=20131312955'));

        // RUC inválido: no se consulta la API.
        $this->actingAs($this->admin)->getJson('/api/v1/clientes/consultar-documento?tipo=6&numero=20512345678')->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_clientes_aislados_por_empresa_y_permisos(): void
    {
        $otra = Empresa::factory()->create();
        $ajeno = Cliente::create(['empresa_id' => $otra->id, 'tipo_documento' => '1', 'numero_documento' => '12345678', 'razon_social' => 'AJENO']);
        $this->actingAs($this->admin)->getJson("/api/v1/clientes/{$ajeno->id}")->assertNotFound();
        $this->actingAs($this->admin)->getJson('/api/v1/clientes')->assertJsonPath('meta.total', 0);

        $contador = User::factory()->create();
        $contador->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($contador)->getJson('/api/v1/clientes')->assertOk();
        $this->actingAs($contador)->postJson('/api/v1/clientes', ['tipo_documento' => '1', 'numero_documento' => '72243561', 'razon_social' => 'X'])->assertForbidden();
    }

    // ---------------- Productos ----------------

    private function producto(array $o = []): array
    {
        return $o + ['codigo' => 'PROD-001', 'descripcion' => 'Laptop Lenovo ThinkPad E14', 'tipo' => 'bien', 'unidad' => 'NIU',
            'precio_venta' => 2360, 'tip_afe_igv' => '10', 'cod_producto_sunat' => '43211508'];
    }

    public function test_crea_producto_con_presentaciones(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['presentaciones' => [
            ['nombre' => 'Caja x 5', 'unidad' => 'BX', 'factor' => 5, 'precio_venta' => 11500],
        ]]))->assertCreated()->assertJsonPath('data.presentaciones.0.factor', 5)->assertJsonPath('data.precio_venta', 2360);
    }

    public function test_validaciones_tributarias_del_producto(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['tip_afe_igv' => '37']))
            ->assertStatus(422)->assertJsonPath('errors.tip_afe_igv.0', 'El tipo de afectación no existe o no está disponible.');
        $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['unidad' => 'XYZ']))->assertStatus(422)->assertJsonValidationErrors('unidad');
        $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['aplica_icbper' => true]))->assertStatus(422)->assertJsonValidationErrors('factor_icbper');

        $isc = $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['codigo' => 'ISC1', 'porcentaje_isc' => 17]))->assertCreated();
        $this->assertSame('01', Producto::find($isc->json('data.id'))->tip_sis_isc);

        $serv = $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['codigo' => 'SERV', 'tipo' => 'servicio', 'unidad' => 'ZZ', 'controla_stock' => true]))->assertCreated();
        $this->assertFalse($serv->json('data.controla_stock'));
    }

    // ---------------- Vista previa ----------------

    public function test_preview_con_productos_usa_el_motor_validado(): void
    {
        // Mismo caso que F-14 (ISC 17 %, aceptado por SUNAT): 2 × 138.06 → 200 + ISC 34 + IGV 42.12 = 276.12
        $isc = $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['codigo' => 'P001', 'precio_venta' => 138.06, 'porcentaje_isc' => 17]))->json('data.id');
        $bolsa = $this->actingAs($this->admin)->postJson('/api/v1/productos', $this->producto(['codigo' => 'BOLSA', 'descripcion' => 'Bolsa', 'precio_venta' => 0.059, 'aplica_icbper' => true, 'factor_icbper' => 0.5]))->json('data.id');

        $r = $this->actingAs($this->admin)->postJson('/api/v1/ventas/preview', ['items' => [
            ['producto_id' => $isc, 'cantidad' => 2],
            ['producto_id' => $bolsa, 'cantidad' => 2],
        ]])->assertOk();

        $this->assertSame('276.12', $r->json('data.items.0.total'));
        $this->assertSame('34.00', $r->json('data.totales.isc'));
        $this->assertSame('1.00', $r->json('data.totales.icbper'));   // 2 × S/ 0.50
        $this->assertEquals(18, $r->json('data.tasa_igv'));
    }

    public function test_preview_rechaza_afectacion_no_disponible(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/ventas/preview', ['items' => [
            ['descripcion' => 'Muestra', 'cantidad' => 1, 'precio_unitario' => 0, 'mto_valor_unitario' => 10, 'tip_afe_igv' => '37'],
        ]])->assertStatus(422)->assertJsonValidationErrors('items');
    }
}
