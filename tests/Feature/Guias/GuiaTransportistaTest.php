<?php

namespace Tests\Feature\Guias;

use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\GuiaRemision;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\ProcesoSunat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuiaTransportistaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Serie $v002;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0001', 'api_sucursal_id' => 14]);
        $this->v002 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '31', 'serie' => 'V002', 'api_serie_id' => 300]);
    }

    private function body(array $o = []): array
    {
        return $o + ['idempotency_key' => (string) Str::uuid(), 'serie_id' => $this->v002->id, 'cod_traslado' => '01', 'fecha_traslado' => now('America/Lima')->toDateString(),
            'remitente' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'],
            'destinatario' => ['tipo_doc' => '6', 'num_doc' => '20100164958', 'razon_social' => 'EMMSA'],
            'doc_relacionado' => ['tipo_codigo' => '09', 'numero' => 'T001-2', 'ruc_emisor' => '20131312955'],
            'partida' => ['ubigeo' => '150101', 'direccion' => 'AV. ALMACEN 100'], 'llegada' => ['ubigeo' => '040101', 'direccion' => 'AV. DESTINO 200'],
            'peso_total' => 500, 'num_bultos' => 5, 'vehiculo' => ['placa' => 'abc123', 'nro_circulacion' => 'ABC321981R'],
            'conductores' => [['tipo_doc' => '1', 'num_doc' => '12345678', 'nombres' => 'JUAN', 'apellidos' => 'PEREZ', 'licencia' => 'q12345678']],
            'items' => [['descripcion' => 'Mercancia', 'unidad' => 'NIU', 'cantidad' => 10]]];
    }

    public function test_emite_con_la_estructura_de_postman_y_reutiliza_el_flujo_de_guias(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias/transportista', $this->body())->assertCreated()
            ->assertJsonPath('data.numero', 'V002-1')->assertJsonPath('data.tipo_documento', '31')
            ->assertJsonPath('data.remitente.num_doc', '20131312955')->assertJsonPath('data.conductores.0.licencia', 'Q12345678');
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        $p = $g->payload;
        $this->assertEquals(['31', 'V002', 1, 'ABC123'], [$p['tipo_documento'], $p['serie'], $p['correlativo'], $p['vehiculo']['placa']]);
        $this->assertTrue(array_is_list($p['conductor']));

        $base = rtrim(config('services.sunat_api.base_url'), '/');
        Http::fake(["$base/guias-remision" => Http::response(['estado' => 'exito', 'datos' => ['id' => 77, 'sunat' => ['estado' => 'enviado']]], 201)]);
        app(ProcesoSunat::class)->emitirGuia($g);
        $this->assertSame(['enviado', 77], [$g->fresh()->estado, (int) $g->fresh()->api_id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['tipo_documento'] === '31' && $r['doc_relacionado']['numero'] === 'T001-2');
    }

    public function test_validaciones_y_permisos(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias/transportista', $this->body(['vehiculo' => ['placa' => 'A-1']]))->assertUnprocessable()->assertJsonValidationErrors('vehiculo.placa');
        $this->actingAs($this->admin)->postJson('/api/v1/guias/transportista', $this->body(['fecha_traslado' => '2020-01-01']))->assertUnprocessable()->assertJsonValidationErrors('fecha_traslado');
        $f = Serie::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '09', 'serie' => 'T002', 'api_serie_id' => 301]);
        $this->actingAs($this->admin)->postJson('/api/v1/guias/transportista', $this->body(['serie_id' => $f->id]))->assertUnprocessable()->assertJsonValidationErrors('serie_id');
        $c = User::factory()->create();
        $c->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($c)->postJson('/api/v1/guias/transportista', $this->body())->assertForbidden();
    }
}
