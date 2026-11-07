<?php

namespace Tests\Feature\Facturacion;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Comunicacion;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\BajaService;
use App\Modules\Facturacion\Services\EmisionService;
use App\Modules\Facturacion\Services\ResumenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Envío de boletas por resumen diario. Respuestas simuladas = estructuras reales de la Etapa 1 (B-06, RC-03 y su /estado). */
class ResumenesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Serie $f001;
    private Serie $b001;
    private Cliente $cliente;
    private Producto $servicio;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648', 'envio_boletas' => 'resumen']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 14]);
        $this->f001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '01', 'serie' => 'F001', 'api_serie_id' => 47]);
        $this->b001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '03', 'serie' => 'B001', 'correlativo_actual' => 271, 'api_serie_id' => 48]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);
        $this->servicio = Producto::create(['empresa_id' => $this->empresa->id, 'codigo' => 'P001', 'descripcion' => 'Producto', 'unidad' => 'ZZ', 'precio_venta' => 10, 'tip_afe_igv' => '10', 'tipo' => 'servicio', 'controla_stock' => false]);
    }

    private function vender(string $tipo = '03', array $extra = []): Comprobante
    {
        $this->actingAs($this->admin)->postJson('/api/v1/ventas', $extra + ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => $tipo,
            'serie_id' => $tipo === '01' ? $this->f001->id : $this->b001->id, 'cliente_id' => $this->cliente->id,
            'items' => [['producto_id' => $this->servicio->id, 'cantidad' => 1]]])->assertCreated();

        return Comprobante::withoutGlobalScopes()->latest('id')->first();
    }

    /** Respuesta real de B-06 ("Boleta registrada. Pendiente de envío vía resumen diario."). */
    private function respuestaRegistro(int $id, int $correlativo): array
    {
        return ['estado' => 'exito', 'mensaje' => 'Boleta registrada. Pendiente de envío vía resumen diario.', 'datos' => [
            'id' => $id, 'tipo_documento' => '03', 'serie' => 'B001', 'correlativo' => $correlativo, 'cliente' => ['tipo_doc' => '6', 'num_doc' => '20131312955'],
            'totales' => ['gravadas' => 8.47, 'igv' => 1.53, 'valor_venta' => 8.47, 'sub_total' => 10, 'total' => 10],
            'sunat' => ['estado' => 'pendiente', 'codigo' => null, 'descripcion' => null, 'notas' => null, 'hash_cpe' => null]]];
    }

    private function registrada(int $apiId = 508): Comprobante
    {
        $b = $this->vender();
        Http::fake(["{$this->base}/boletas" => fn (Request $r) => Http::response($this->respuestaRegistro($r['correlativo'] + 236, $r['correlativo']), 201)]);
        $this->assertSame($apiId, $b->correlativo + 236); // B001-272 → id 508 (como en B-06)
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->emitir(\App\Models\Comprobante::withoutGlobalScopes()->find($b->id));

        return $b->fresh();
    }

    public function test_boleta_en_modo_resumen_se_registra_con_solo_registro_y_no_se_consulta(): void
    {
        $b = $this->registrada();
        $this->assertTrue($b->envio_por_resumen);
        $this->assertTrue($b->payload['solo_registro']);
        $this->assertArrayNotHasKey('enviar_automatico', $b->payload);
        $this->assertSame(['pendiente_resumen', 508], [$b->estado, (int) $b->api_id]);
        Http::assertSent(fn (Request $r) => $r['solo_registro'] === true);

        // Las facturas no cambian: siguen enviándose una por una.
        $f = $this->vender('01');
        $this->assertFalse($f->envio_por_resumen);
        $this->assertTrue($f->payload['enviar_automatico']);
        $this->assertArrayNotHasKey('solo_registro', $f->payload);
    }

    public function test_modo_individual_no_cambia_el_payload_de_boletas(): void
    {
        $this->empresa->update(['envio_boletas' => 'individual']);
        $b = $this->vender();
        $this->assertFalse($b->envio_por_resumen);
        $this->assertTrue($b->payload['enviar_automatico']);
        $this->assertArrayNotHasKey('solo_registro', $b->payload);
    }

    public function test_el_modo_de_envio_se_puede_elegir_en_cada_boleta(): void
    {
        // Empresa en modo resumen, pero esta boleta se pide individual.
        $b = $this->vender('03', ['envio_boleta' => 'individual']);
        $this->assertFalse($b->envio_por_resumen);
        $this->assertTrue($b->payload['enviar_automatico']);
        $this->assertArrayNotHasKey('solo_registro', $b->payload);

        // Empresa en modo individual, pero esta boleta se pide por resumen.
        $this->empresa->update(['envio_boletas' => 'individual']);
        $r = $this->vender('03', ['envio_boleta' => 'resumen']);
        $this->assertTrue($r->envio_por_resumen);
        $this->assertTrue($r->payload['solo_registro']);

        // En facturas la opción no aplica: siempre se envían una por una.
        $f = $this->vender('01', ['envio_boleta' => 'resumen']);
        $this->assertFalse($f->envio_por_resumen);
        $this->assertArrayNotHasKey('solo_registro', $f->payload);

        $this->actingAs($this->admin)->postJson('/api/v1/ventas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '03',
            'envio_boleta' => 'otro', 'serie_id' => $this->b001->id, 'items' => [['producto_id' => $this->servicio->id, 'cantidad' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('envio_boleta');
    }

    public function test_rc_de_envio_completo_con_documento_externo(): void
    {
        $b = $this->registrada();
        $hoy = now('America/Lima')->toDateString();
        $ayer = now('America/Lima')->subDay()->toDateString();

        $manana = now('America/Lima')->addDay()->toDateString();
        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $manana])->assertStatus(422);
        $b->update(['fecha_emision' => $ayer]);

        $this->actingAs($this->admin)->getJson('/api/v1/resumenes/pendientes')->assertOk()
            ->assertJsonPath('data.modo', 'resumen')->assertJsonPath('data.fechas.0.fecha', $ayer)->assertJsonPath('data.fechas.0.registradas', 1);

        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $ayer])->assertCreated()
            ->assertJsonPath('data.accion', 'envio')->assertJsonPath('data.tipo_label', 'Resumen diario de envío');
        $com = Comunicacion::withoutGlobalScopes()->sole();
        $this->assertEquals(['fecha_resumen' => $ayer, 'enviar_automatico' => false], $com->payload); // ejemplo RC-03

        // No se crea otro RC de la fecha mientras el primero está en curso (H-5)
        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $ayer])->assertStatus(422);

        $doc = fn ($id, $nro, $estado) => ['id' => $id, 'tipo_documento' => '03', 'serie_nro' => $nro, 'estado' => '1', 'total' => 10, 'estado_sunat' => $estado];
        Http::fake([
            "{$this->base}/resumenes" => Http::response(['estado' => 'exito', 'mensaje' => 'Resumen diario creado en estado pendiente. Use POST /resumenes/{id}/enviar para enviarlo a SUNAT.',
                'datos' => ['id_resumen' => 56, 'identifier' => 'RC-20261105-002', 'accion' => 'envio', 'total_documentos' => 2, 'estado_sunat' => 'pendiente',
                    'documentos' => [$doc(508, "B001-{$b->correlativo}", 'pendiente'), $doc(999, 'B001-900', 'pendiente')]]], 201),
            "{$this->base}/resumenes/56/enviar" => Http::response(['estado' => 'exito', 'mensaje' => 'Resumen enviado a SUNAT.',
                'datos' => ['id_resumen' => 56, 'identifier' => 'RC-20261105-002', 'estado_sunat' => 'enviado']]),
            "{$this->base}/resumenes/56/estado" => Http::response(['estado' => 'exito', 'datos' => ['id_resumen' => 56, 'identifier' => 'RC-20261105-002',
                'ticket' => '1793930965505', 'estado_sunat' => 'aceptado', 'codigo_sunat' => '0', 'descripcion_sunat' => 'El Resumen diario RC-20261105-002, ha sido aceptado',
                'tipo' => 'envio', 'documentos' => [$doc(508, "B001-{$b->correlativo}", 'aceptado'), $doc(999, 'B001-900', 'aceptado')]]]),
        ]);
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->enviarComunicacion(\App\Models\Comunicacion::withoutGlobalScopes()->find($com->id));
        $com->refresh();
        $this->assertSame(['enviado', 56, 'RC-20261105-002', 1], [$com->estado, (int) $com->api_id, $com->identifier, (int) $com->documentos_externos]);
        $this->assertSame('enviado', $b->fresh()->estado);
        Http::assertSent(fn (Request $r) => $r->url() === "{$this->base}/resumenes/56/enviar" && $r->method() === 'POST');

        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->actualizarComunicacion(\App\Models\Comunicacion::withoutGlobalScopes()->find($com->id), forzar: true);
        $this->assertSame('aceptado', $com->fresh()->estado);
        $b->refresh();
        $this->assertSame(['aceptado', '0'], [$b->estado, $b->sunat_codigo]);
        $this->assertNotNull($b->aceptado_at);
        $this->assertSame(1, $com->items()->count()); // solo la boleta propia

        // Una boleta aceptada por resumen ya admite baja (RC de anulación).
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$b->id], 'motivo' => 'Cliente solicita anulación'])->assertCreated();
    }

    public function test_el_resumen_de_hoy_se_envia_solo_manualmente(): void
    {
        $b = $this->registrada();
        $hoy = now('America/Lima')->toDateString();

        // El envío automático no lo toca...
        $this->artisan('sunat:enviar-resumenes')->assertSuccessful();
        $this->assertSame(0, Comunicacion::withoutGlobalScopes()->count());
        try {
            app(ResumenService::class)->solicitar($this->empresa, $this->admin, $hoy);
            $this->fail('El resumen de hoy no debe crearse sin permitirHoy.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('manualmente', collect($e->errors())->flatten()->first());
        }

        // ...pero el usuario sí puede enviarlo desde la pantalla.
        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $hoy])->assertCreated();
        $com = Comunicacion::withoutGlobalScopes()->sole();
        $this->assertSame([$b->id], $com->items()->pluck('comprobante_id')->all());
        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $hoy])->assertStatus(422); // en curso

        // Aceptado el primero, una boleta emitida después el mismo día puede ir en otro resumen.
        $com->update(['estado' => 'aceptado']);
        $b->update(['estado' => 'aceptado']);
        $otra = $this->vender();
        $otra->update(['estado' => 'pendiente_resumen', 'api_id' => 777]);
        $this->actingAs($this->admin)->getJson('/api/v1/resumenes/pendientes')->assertOk()
            ->assertJsonPath('data.fechas.0.resumen_activo', null)->assertJsonPath('data.fechas.0.registradas', 1);
        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $hoy])->assertCreated();
        $this->assertSame([$otra->id], Comunicacion::withoutGlobalScopes()->latest('id')->first()->items()->pluck('comprobante_id')->all());
    }

    public function test_descarga_xml_y_cdr_del_resumen_y_muestra_el_ticket(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $com = Comunicacion::create(['empresa_id' => $this->empresa->id, 'tipo' => 'RC', 'accion' => 'envio', 'fecha_documentos' => '2026-11-06',
            'motivo' => 'Envío de boletas', 'estado' => 'aceptado', 'api_id' => 59, 'identifier' => 'RC-20261106-002', 'ticket' => '1794016558913',
            'user_id' => $this->admin->id, 'payload' => []]);
        Http::fake([
            "{$this->base}/resumenes/59/xml" => Http::response('<?xml version="1.0"?><SummaryDocuments/>', 200, ['Content-Type' => 'application/xml']),
            "{$this->base}/resumenes/59/cdr" => Http::response("PK\x03\x04cdr", 200, ['Content-Type' => 'application/zip']),
        ]);

        $this->actingAs($this->admin)->getJson('/api/v1/bajas?accion=envio')->assertOk()
            ->assertJsonPath('data.0.ticket', '1794016558913')->assertJsonPath('data.0.tiene_archivos', true);

        $xml = $this->actingAs($this->admin)->get("/api/v1/bajas/{$com->id}/archivos/xml")->assertOk();
        $this->assertStringContainsString('attachment; filename=20161515648-RC-20261106-002.xml', $xml->headers->get('Content-Disposition'));
        $this->assertStringContainsString('SummaryDocuments', $xml->streamedContent());
        $cdr = $this->actingAs($this->admin)->get("/api/v1/bajas/{$com->id}/archivos/cdr")->assertOk();
        $this->assertStringContainsString('R-20161515648-RC-20261106-002.zip', $cdr->headers->get('Content-Disposition'));

        // Ya guardados: la segunda descarga no vuelve a llamar a la API.
        $this->actingAs($this->admin)->get("/api/v1/bajas/{$com->id}/archivos/cdr")->assertOk();
        Http::assertSentCount(2);
        $this->actingAs($this->admin)->get("/api/v1/bajas/{$com->id}/archivos/pdf")->assertNotFound();
    }

    public function test_cdr_no_disponible_devuelve_404_con_el_mensaje_de_la_api(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $com = Comunicacion::create(['empresa_id' => $this->empresa->id, 'tipo' => 'RA', 'accion' => 'anulacion', 'fecha_documentos' => '2026-11-06',
            'motivo' => 'Error', 'estado' => 'aceptado', 'api_id' => 43, 'identifier' => 'RA-20261106-003', 'user_id' => $this->admin->id, 'payload' => []]);
        Http::fake(["{$this->base}/anulaciones/43/cdr" => Http::response(['estado' => 'error', 'mensaje' => 'CDR de la anulación no disponible'], 404)]);

        $this->actingAs($this->admin)->getJson("/api/v1/bajas/{$com->id}/archivos/cdr")
            ->assertNotFound()->assertJsonPath('message', 'CDR de la anulación no disponible');
    }

    public function test_si_la_api_crea_el_rc_pero_falla_el_envio_el_reintento_no_crea_otro(): void
    {
        $b = $this->registrada();
        $b->update(['fecha_emision' => now('America/Lima')->subDay()->toDateString()]);
        $com = app(ResumenService::class)->solicitar($this->empresa, $this->admin, $b->fecha_emision->toDateString());

        Http::fake([
            "{$this->base}/resumenes" => Http::response(['estado' => 'exito', 'datos' => ['id_resumen' => 60, 'identifier' => 'RC-20261105-004', 'estado_sunat' => 'pendiente',
                'documentos' => [['id' => 508, 'tipo_documento' => '03', 'serie_nro' => 'B001-272', 'estado_sunat' => 'pendiente']]]], 201),
            "{$this->base}/resumenes/60/enviar" => Http::sequence()->push(['estado' => 'error', 'mensaje' => 'Error interno'], 500)
                ->push(['estado' => 'exito', 'datos' => ['id_resumen' => 60, 'identifier' => 'RC-20261105-004', 'estado_sunat' => 'enviado']]),
        ]);
        try {
            app(ResumenService::class)->enviar($com);
            $this->fail('Debió lanzar excepción de conexión para reintentar');
        } catch (\App\Integrations\SunatApi\Exceptions\SunatApiConnectionException) {
        }
        $this->assertSame(['por_enviar', 60], [$com->fresh()->estado, (int) $com->fresh()->api_id]);

        $this->assertTrue(app(ResumenService::class)->enviar($com->fresh()));
        $this->assertSame('enviado', $com->fresh()->estado);
        Http::assertSentCount(3); // 1 creación + 2 envíos: el reintento NO crea otro RC
    }

    public function test_rc_duplicado_por_otro_sistema_consulta_cada_boleta(): void
    {
        $b = $this->registrada();
        $b->update(['fecha_emision' => now('America/Lima')->subDay()->toDateString()]);
        $com = app(ResumenService::class)->solicitar($this->empresa, $this->admin, $b->fecha_emision->toDateString());

        Http::fake([
            "{$this->base}/resumenes" => Http::response(['estado' => 'error', 'mensaje' => 'Las boletas de la fecha 2026-11-05 ya están incluidas en un resumen (en proceso o aceptado). Consulta su estado en vez de generar otro.'], 422),
            "{$this->base}/boletas/508" => Http::response(['estado' => 'exito', 'datos' => ['id' => 508, 'serie' => 'B001', 'correlativo' => $b->correlativo,
                'totales' => ['total' => 10], 'sunat' => ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'aceptada']]]),
        ]);
        $this->assertFalse(app(ResumenService::class)->enviar($com));
        $com->refresh();
        $this->assertSame('error', $com->estado);
        $this->assertStringContainsString('Se consultó el estado de cada boleta', $com->ultimo_error);
        $this->assertSame('aceptado', $b->fresh()->estado);
    }

    public function test_rc_rechazado_devuelve_las_boletas_a_pendiente(): void
    {
        $b = $this->registrada();
        $b->update(['fecha_emision' => now('America/Lima')->subDay()->toDateString()]);
        $com = app(ResumenService::class)->solicitar($this->empresa, $this->admin, $b->fecha_emision->toDateString());
        $com->update(['api_id' => 56, 'estado' => 'enviado']);
        $b->update(['estado' => 'enviado']);
        Http::fake(["{$this->base}/resumenes/56/estado" => Http::response(['estado' => 'exito', 'datos' => ['id_resumen' => 56, 'identifier' => 'RC-20261105-002',
            'estado_sunat' => 'rechazado', 'codigo_sunat' => '2282', 'descripcion_sunat' => 'Documento ya informado']])]);
        app(ResumenService::class)->consultar($com);
        $this->assertSame('rechazado', $com->fresh()->estado);
        $this->assertSame('pendiente_resumen', $b->fresh()->estado);
        // Tras un rechazo se puede generar un nuevo resumen para la fecha.
        $this->actingAs($this->admin)->postJson('/api/v1/resumenes', ['fecha' => $b->fecha_emision->toDateString()])->assertCreated();
    }

    public function test_comando_programado_resume_solo_fechas_anteriores(): void
    {
        $vieja = $this->registrada(508);
        $vieja->update(['fecha_emision' => now('America/Lima')->subDays(2)->toDateString()]);
        $this->registrada(509); // de hoy: no se resume todavía

        $this->artisan('sunat:enviar-resumenes')->assertSuccessful();
        $com = Comunicacion::withoutGlobalScopes()->sole();
        $this->assertSame([$vieja->fecha_emision->toDateString(), 'envio'], [$com->fecha_documentos->toDateString(), $com->accion]);
        $this->assertSame([$vieja->id], $com->items()->pluck('comprobante_id')->all());

        $this->artisan('sunat:enviar-resumenes')->assertSuccessful(); // idempotente
        $this->assertSame(1, Comunicacion::withoutGlobalScopes()->count());
    }

    public function test_bajas_no_lista_resumenes_de_envio(): void
    {
        $b = $this->registrada();
        $b->update(['fecha_emision' => now('America/Lima')->subDay()->toDateString()]);
        app(ResumenService::class)->solicitar($this->empresa, $this->admin, $b->fecha_emision->toDateString());
        $this->actingAs($this->admin)->getJson('/api/v1/bajas')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/bajas?accion=envio')->assertOk()->assertJsonCount(1, 'data');
    }
}
