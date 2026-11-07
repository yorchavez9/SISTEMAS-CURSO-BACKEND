<?php

namespace Tests\Feature\Guias;

use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\GuiaRemision;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Guias\Services\GuiaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Guías de remisión remitente. Respuestas simuladas = estructuras reales (GRR-01…06, POLL_GRR-*, prueba de correlativo 2026-11-06). */
class GuiasTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Establecimiento $origen;
    private Establecimiento $sucursal;
    private Serie $t001;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648', 'razon_social' => 'CLIENTE DEMO SAC']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $this->origen = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Almacén', 'cod_local' => '0000', 'ubigeo' => '150203', 'direccion' => 'AV ITALIA 123, RIMAC', 'api_sucursal_id' => 14]);
        $this->sucursal = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal', 'cod_local' => '0001', 'ubigeo' => '150101', 'direccion' => 'AV LIMA 456, CERCADO', 'api_sucursal_id' => 15]);
        $this->t001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $this->origen->id, 'tipo_documento' => '09', 'serie' => 'T001', 'correlativo_actual' => 14, 'api_serie_id' => 51]);
    }

    private function datos(array $o = []): array
    {
        return array_replace_recursive([
            'idempotency_key' => (string) Str::uuid(), 'serie_id' => $this->t001->id, 'fecha_traslado' => now('America/Lima')->toDateString(),
            'cod_traslado' => '01', 'mod_traslado' => '02', 'peso_total' => 50, 'num_bultos' => 5,
            'destinatario' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'],
            'llegada' => ['ubigeo' => '150101', 'direccion' => 'AV LIMA 456, CERCADO'],
            'vehiculo' => ['placa' => 'abc123', 'secundarias' => ['XYZ789']],
            'conductores' => [['tipo_doc' => '1', 'num_doc' => '44004400', 'nombres' => 'ROBERTO', 'apellidos' => 'RODRIGUEZ VALENCIA', 'licencia' => 'Q44004400']],
            'items' => [['codigo' => 'PROD1', 'descripcion' => 'Laptop HP ProBook 450', 'unidad' => 'NIU', 'cantidad' => 10]],
        ], $o);
    }

    private function respuesta(int $id, int $correlativo, array $sunat): array
    {
        return ['estado' => 'exito', 'mensaje' => 'Guía de remisión creada y encolada para envío a SUNAT.', 'datos' => [
            'id' => $id, 'serie' => 'T001', 'correlativo' => $correlativo, 'numero_completo' => sprintf('T001-%06d', $correlativo),
            'destinatario' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'], 'items' => [['descripcion' => 'Laptop HP ProBook 450', 'cantidad' => 10, 'unidad' => 'NIU']],
            'sunat' => $sunat + ['estado' => 'enviado', 'codigo' => null, 'descripcion' => null, 'ticket' => null], 'archivos' => ['xml' => null, 'cdr' => null, 'pdf' => null]]];
    }

    public function test_transporte_privado_payload_como_grr02_con_correlativo_explicito(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos())->assertCreated()->assertJsonPath('data.numero', 'T001-15');
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        $hoy = now('America/Lima')->toDateString();
        $this->assertEquals([
            'serie' => 'T001', 'correlativo' => 15, 'fecha_emision' => $hoy,
            'destinatario' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'],
            'cod_traslado' => '01', 'mod_traslado' => '02', 'fecha_traslado' => $hoy, 'peso_total' => 50.0, 'und_peso_total' => 'KGM', 'num_bultos' => 5,
            'partida_ubigeo' => '150203', 'partida_direccion' => 'AV ITALIA 123, RIMAC', 'llegada_ubigeo' => '150101', 'llegada_direccion' => 'AV LIMA 456, CERCADO',
            'vehiculo' => ['placa' => 'ABC123', 'secundarios' => [['placa' => 'XYZ789']]],
            'conductor' => ['tipo' => 'Principal', 'tipo_doc' => '1', 'num_doc' => '44004400', 'nombres' => 'ROBERTO', 'apellidos' => 'RODRIGUEZ VALENCIA', 'licencia' => 'Q44004400'],
            'items' => [['codigo' => 'PROD1', 'descripcion' => 'Laptop HP ProBook 450', 'cantidad' => 10.0, 'unidad' => 'NIU']],
            'enviar_automatico' => true,
        ], $g->payload);
        $this->assertSame(15, (int) $this->t001->fresh()->correlativo_actual);

        // Idempotencia
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['idempotency_key' => $g->idempotency_key]))->assertCreated();
        $this->assertSame(1, GuiaRemision::withoutGlobalScopes()->count());
    }

    public function test_transporte_publico_y_m1l_y_varios_conductores(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['mod_traslado' => '01', 'vehiculo' => null, 'conductores' => null,
            'transportista' => ['num_doc' => '20131312955', 'razon_social' => 'TRANSPORTES SAC', 'nro_mtc' => '0001']]))->assertCreated();
        $p = GuiaRemision::withoutGlobalScopes()->latest('id')->first()->payload;
        $this->assertEquals(['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'TRANSPORTES SAC', 'nro_mtc' => '0001'], $p['transportista']); // MySQL JSON reordena claves
        $this->assertSame($p['fecha_traslado'], $p['fecha_de_entrega_al_transportista']);
        $this->assertArrayNotHasKey('vehiculo', $p);

        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['vehiculo_m1l' => true, 'vehiculo' => null, 'conductores' => null]))->assertCreated();
        $p = GuiaRemision::withoutGlobalScopes()->latest('id')->first()->payload;
        $this->assertSame(['SUNAT_Envio_IndicadorTrasladoVehiculoM1L'], $p['indicadores']);
        $this->assertArrayNotHasKey('conductor', $p);

        $d = $this->datos();
        $d['conductores'][] = ['tipo_doc' => '1', 'num_doc' => '55005500', 'nombres' => 'PEDRO', 'apellidos' => 'LOPEZ RUIZ', 'licencia' => 'Q55005500'];
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $d)->assertCreated();
        $p = GuiaRemision::withoutGlobalScopes()->latest('id')->first()->payload;
        $this->assertSame(['Principal', 'Secundario'], array_column($p['conductores'], 'tipo')); // GRR-05
    }

    public function test_motivo_04_destinatario_es_el_emisor_y_locales_por_codigo(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['cod_traslado' => '04', 'destinatario' => null, 'llegada' => null,
            'establecimiento_destino_id' => $this->sucursal->id]))->assertCreated();
        $p = GuiaRemision::withoutGlobalScopes()->sole()->payload;
        $this->assertEquals(['tipo_doc' => '6', 'num_doc' => '20161515648', 'razon_social' => 'CLIENTE DEMO SAC'], $p['destinatario']);
        $this->assertSame(['20161515648', '0000', '20161515648', '0001', '150101'], [$p['partida_ruc'], $p['partida_cod_local'], $p['llegada_ruc'], $p['llegada_cod_local'], $p['llegada_ubigeo']]);
    }

    public function test_validaciones(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['vehiculo' => ['placa' => null], 'conductores' => null]))->assertStatus(422)->assertJsonValidationErrors('vehiculo.placa');
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['llegada' => ['ubigeo' => '1501']]))->assertStatus(422)->assertJsonValidationErrors('llegada.ubigeo');
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['fecha_traslado' => now('America/Lima')->subDay()->toDateString()]))->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['mod_traslado' => '01', 'transportista' => ['num_doc' => '20131312950', 'razon_social' => 'X']]))->assertStatus(422)->assertJsonValidationErrors('transportista.num_doc');
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos(['cod_traslado' => '04', 'destinatario' => null, 'llegada' => null, 'establecimiento_destino_id' => $this->origen->id]))->assertStatus(422);
        $this->assertSame(0, GuiaRemision::withoutGlobalScopes()->count());
        $this->assertSame(14, (int) $this->t001->fresh()->correlativo_actual);
    }

    public function test_cliente_no_autorizado_queda_en_error_reenviable_y_se_reenvia_sin_nuevo_numero(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos())->assertCreated();
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        Http::fake([
            "{$this->base}/guias-remision" => Http::response($this->respuesta(98, 15, []), 201),
            "{$this->base}/guias-remision/98/estado" => Http::response(['estado' => 'error', 'mensaje' => 'La guía no tiene ticket SUNAT porque el envío inicial falló antes de recibir ticket.',
                'guia' => ['id' => 98, 'numero_completo' => 'T001-000015', 'estado_sunat' => 'rechazado', 'codigo_sunat' => 'BUILD_ERROR', 'descripcion_sunat' => 'Cliente No autorizado', 'tiene_ticket' => false],
                'siguiente_accion' => 'Corrija el error indicado y use POST /guias-remision/98/enviar para reintentar.'], 409),
            "{$this->base}/guias-remision/98/enviar" => Http::response(['estado' => 'exito', 'mensaje' => 'Guía de remisión enviada a SUNAT.', 'datos' => $this->respuesta(98, 15, ['estado' => 'enviado'])['datos']]),
        ]);
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->emitirGuia(\App\Models\GuiaRemision::withoutGlobalScopes()->find($g->id));
        $this->assertSame(['enviado', 98], [$g->fresh()->estado, (int) $g->fresh()->api_id]);

        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->actualizarGuia(\App\Models\GuiaRemision::withoutGlobalScopes()->find($g->id), forzar: true);
        $g->refresh();
        $this->assertSame(['error', 'BUILD_ERROR'], [$g->estado, $g->sunat_codigo]);
        $this->assertStringContainsString('credenciales GRE', collect($g->historial)->last()['detalle']);

        $this->actingAs($this->admin)->postJson("/api/v1/guias/{$g->id}/reintentar")->assertOk();
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->emitirGuia(\App\Models\GuiaRemision::withoutGlobalScopes()->find($g->id));
        $this->assertSame('enviado', $g->fresh()->estado);
        Http::assertSent(fn (Request $r) => $r->url() === "{$this->base}/guias-remision/98/enviar" && $r->method() === 'POST');
        Http::assertSentCount(3); // POST inicial, /estado y /enviar: nunca un segundo POST /guias-remision
        $this->assertSame(15, $g->fresh()->correlativo);
    }

    public function test_aceptada_descarga_archivos(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos())->assertCreated();
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        $g->update(['estado' => 'enviado', 'api_id' => 98]);
        // Respuesta real de /estado de una guía aceptada (T009-6, beta 2026-11-06): la guía completa con su bloque "sunat".
        Http::fake([
            "{$this->base}/guias-remision/98/estado" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => $this->respuesta(98, 15,
                ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'ACEPTADA', 'ticket' => 'abc-123'])['datos']]),
        ]);
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->actualizarGuia(\App\Models\GuiaRemision::withoutGlobalScopes()->find($g->id), forzar: true);
        $g->refresh();
        $this->assertSame(['aceptado', '0', 'abc-123'], [$g->estado, $g->sunat_codigo, $g->ticket]);
        $this->assertNotNull($g->aceptado_at);
    }

    public function test_estado_con_formato_desconocido_lee_la_guia_completa(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos())->assertCreated();
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        $g->update(['estado' => 'enviado', 'api_id' => 98]);
        Http::fake([
            "{$this->base}/guias-remision/98/estado" => Http::response(['estado' => 'exito', 'mensaje' => 'Consultado']),
            "{$this->base}/guias-remision/98" => Http::response(['estado' => 'exito', 'datos' => $this->respuesta(98, 15, ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'aceptada'])['datos']]),
        ]);
        app(GuiaService::class)->consultar($g);
        $this->assertSame('aceptado', $g->fresh()->estado);
    }

    public function test_correlativo_ocupado_por_otro_documento_renumera(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos())->assertCreated();
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        Http::fake([
            "{$this->base}/guias-remision?*" => Http::response(['estado' => 'exito', 'datos' => ['datos' => [['id' => 80, 'serie' => 'T001', 'correlativo' => 15,
                'destinatario' => ['num_doc' => '20000000002'], 'items' => [[]]]], 'paginacion' => []]]),
            "{$this->base}/guias-remision" => Http::response(['estado' => 'error', 'mensaje' => 'Error de validación', 'errores' => ['correlativo' => ['El correlativo 15 ya existe para la serie T001. Use otro número o deje el campo vacío para autoincremento.']]], 422),
            "{$this->base}/series" => Http::response(['estado' => 'exito', 'datos' => [['serie' => 'T001', 'correlativo' => 19]]]),
        ]);
        $this->assertSame(GuiaService::RESULTADO_RENUMERADO, app(GuiaService::class)->emitir($g));
        $g->refresh();
        $this->assertSame(['por_enviar', 20, 20], [$g->estado, $g->correlativo, $g->payload['correlativo']]);
        $this->assertSame(20, (int) $this->t001->fresh()->correlativo_actual);
    }

    public function test_permisos_y_aislamiento(): void
    {
        $contador = User::factory()->create();
        $contador->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($contador)->getJson('/api/v1/guias')->assertOk();
        $this->actingAs($contador)->postJson('/api/v1/guias', $this->datos())->assertForbidden();

        $this->actingAs($this->admin)->postJson('/api/v1/guias', $this->datos())->assertCreated();
        $g = GuiaRemision::withoutGlobalScopes()->sole();
        $otra = Empresa::factory()->create();
        $this->admin->asignarEnEmpresa($otra, 'admin');
        $this->actingAs($this->admin)->withHeader('X-Empresa-Id', (string) $otra->id)->getJson("/api/v1/guias/{$g->id}")->assertNotFound();
    }
}
