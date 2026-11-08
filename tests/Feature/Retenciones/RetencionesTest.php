<?php

namespace Tests\Feature\Retenciones;

use App\Models\ComprobanteRetencion;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
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

class RetencionesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Serie $r002;
    private Serie $p002;
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
        $this->r002 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '20', 'serie' => 'R002', 'api_serie_id' => 90]);
        $this->p002 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '40', 'serie' => 'P002', 'api_serie_id' => 91]);
    }

    private function retencion(array $o = []): array
    {
        return $o + ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '20', 'serie_id' => $this->r002->id, 'regimen' => '01',
            'tercero' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'],
            'documentos' => [['tipo_doc' => '01', 'num_doc' => 'F001-34', 'fecha_emision' => '2026-11-01', 'imp_total' => 236, 'fecha_pago' => '2026-11-05']]];
    }

    /** Respuesta real de GET /retenciones/5 (beta, 2026-11-07). */
    private function retencionApi(string $estado, int $correlativo = 1): array
    {
        return ['estado' => 'exito', 'datos' => ['id' => 70, 'serie' => 'R002', 'correlativo' => $correlativo, 'numero' => 'R002-0000000'.$correlativo,
            'proveedor' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'], 'regimen' => '01', 'tasa' => 3,
            'imp_retenido' => 7.08, 'imp_pagado' => 228.92, 'sunat_status' => $estado, 'sunat_code' => $estado === 'aceptado' ? '0' : null]];
    }

    public function test_retencion_3_por_ciento_con_payload_postman_y_flujo_hasta_aceptada(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion())->assertCreated()
            ->assertJsonPath('data.numero', 'R002-1')->assertJsonPath('data.importe', 7.08)->assertJsonPath('data.importe_neto', 228.92)
            ->assertJsonPath('data.estado', 'por_enviar');
        $c = ComprobanteRetencion::withoutGlobalScopes()->sole();
        $p = $c->payload;
        // Estructura del ejemplo Postman "Crear retención (régimen general 3%)" + correlativo explícito y cod_local.
        $this->assertEquals(['R002', 1, '0001', '01', 3], [$p['serie'], $p['correlativo'], $p['cod_local'], $p['regimen'], $p['tasa']]);
        $this->assertEquals(['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'], $p['proveedor']);
        $this->assertEquals(['tipo_doc' => '01', 'num_doc' => 'F001-34', 'fecha_emision' => '2026-11-01', 'imp_total' => 236, 'moneda' => 'PEN',
            'fecha_retencion' => '2026-11-05', 'imp_retenido' => 7.08, 'imp_pagar' => 228.92, 'pagos' => [['moneda' => 'PEN', 'importe' => 236, 'fecha' => '2026-11-05']]], $p['documentos'][0]);

        Http::fake([
            "{$this->base}/retenciones" => Http::response($this->retencionApi('enviado'), 201),
            "{$this->base}/retenciones/70" => Http::response($this->retencionApi('aceptado')),
        ]);
        app(ProcesoSunat::class)->emitirRetencion($c);
        $this->assertSame(['enviado', 70], [$c->fresh()->estado, (int) $c->fresh()->api_id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['serie'] === 'R002' && $r['proveedor']['num_doc'] === '20131312955');

        // Al abrirla se consulta su estado en la API (sin cola de trabajos).
        $this->actingAs($this->admin)->getJson("/api/v1/retenciones/{$c->id}")->assertOk()
            ->assertJsonPath('data.estado', 'aceptado')->assertJsonPath('data.sunat_codigo', '0')->assertJsonPath('data.revertible', true);
    }

    public function test_percepcion_2_por_ciento_con_cobros(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion([
            'tipo_documento' => '40', 'serie_id' => $this->p002->id, 'regimen' => '01',
            'documentos' => [['tipo_doc' => '01', 'num_doc' => 'F002-10', 'fecha_emision' => '2026-11-02', 'imp_total' => 1180]],
        ]))->assertCreated()->assertJsonPath('data.tipo_label', 'Percepción')->assertJsonPath('data.importe', 23.6)->assertJsonPath('data.importe_neto', 1203.6);
        $p = ComprobanteRetencion::withoutGlobalScopes()->sole()->payload;
        $this->assertArrayHasKey('cliente', $p);
        $this->assertArrayNotHasKey('proveedor', $p);
        $this->assertSame(1180, $p['documentos'][0]['cobros'][0]['importe']);
        $this->assertArrayHasKey('fecha_percepcion', $p['documentos'][0]);
    }

    public function test_numero_ocupado_por_otro_sistema_se_renumera(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion())->assertCreated();
        $c = ComprobanteRetencion::withoutGlobalScopes()->sole();
        Http::fake([
            "{$this->base}/retenciones" => Http::sequence()
                ->push(['estado' => 'error', 'mensaje' => 'Error de validación', 'errores' => ['correlativo' => ['El correlativo 1 ya existe para la serie R002.']]], 422)
                ->push($this->retencionApi('aceptado', 6), 201),
            "{$this->base}/series" => Http::response(['estado' => 'exito', 'datos' => [['id' => 90, 'tipo_documento' => '20', 'serie' => 'R002', 'correlativo' => 5]]]),
        ]);
        app(ProcesoSunat::class)->emitirRetencion($c);
        $c->refresh();
        $this->assertSame(['R002-6', 'aceptado', 6], [$c->numero(), $c->estado, $this->r002->fresh()->correlativo_actual]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['correlativo'] === 6);
    }

    public function test_reversion_rr_hasta_revertida(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion())->assertCreated();
        $c = ComprobanteRetencion::withoutGlobalScopes()->sole();
        $c->update(['estado' => 'aceptado', 'api_id' => 70]);
        Http::fake([
            // Respuesta real de POST /reversiones (beta, 2026-11-07): el id viene como voided_id.
            "{$this->base}/reversiones" => Http::response(['estado' => 'exito', 'mensaje' => 'Reversión encolada para envío a SUNAT.', 'datos' => [
                'voided_id' => 55, 'identifier' => 'RR-20261107-001', 'correlativo' => '001', 'sunat_status' => 'enviado',
                'consulta_estado' => 'https://sunat.kodevo.es/api/v1/voided/55/status', 'total_documentos' => 1, 'fecha_comunicacion' => '2026-11-07']], 201),
            "{$this->base}/anulaciones/55/estado" => Http::response(['estado' => 'exito', 'datos' => ['id_anulacion' => 55, 'identifier' => 'RR-20261107-001',
                'estado_sunat' => 'aceptado', 'codigo_sunat' => '0', 'descripcion_sunat' => 'El Resumen de reversiones RR-20261107-001, ha sido aceptado']]),
        ]);
        $this->actingAs($this->admin)->postJson("/api/v1/retenciones/{$c->id}/revertir", ['motivo' => 'Error en datos del proveedor'])->assertOk()
            ->assertJsonPath('data.reversion.estado', 'enviado')->assertJsonPath('data.reversion.identifier', 'RR-20261107-001');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/reversiones') && $r['detalles'][0] === [
            'tipo_documento' => '20', 'serie' => 'R002', 'correlativo' => '1', 'motivo' => 'Error en datos del proveedor',
        ] && isset($r['fecha_comunicacion']));
        $this->actingAs($this->admin)->postJson("/api/v1/retenciones/{$c->id}/revertir", ['motivo' => 'Otra vez'])->assertUnprocessable();

        Cache::flush();
        $this->actingAs($this->admin)->getJson("/api/v1/retenciones/{$c->id}")->assertOk()
            ->assertJsonPath('data.estado', 'revertido')->assertJsonPath('data.reversion.estado', 'aceptado')->assertJsonPath('data.revertible', false);
    }

    public function test_descarga_cdr_y_lo_guarda_cuando_es_final(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion())->assertCreated();
        $c = ComprobanteRetencion::withoutGlobalScopes()->sole();
        $c->update(['estado' => 'aceptado', 'api_id' => 70]);
        Http::fake(["{$this->base}/retenciones/70/cdr" => Http::response('PK-cdr', 200, ['Content-Type' => 'application/zip'])]);
        $this->actingAs($this->admin)->get("/api/v1/retenciones/{$c->id}/archivos/cdr")->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->actingAs($this->admin)->get("/api/v1/retenciones/{$c->id}/archivos/cdr")->assertOk();
        Http::assertSentCount(1); // la segunda vez sale del almacenamiento local
    }

    public function test_validaciones_y_permisos(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion(['tercero' => ['tipo_doc' => '1', 'num_doc' => '12345678', 'razon_social' => 'X']]))
            ->assertUnprocessable()->assertJsonValidationErrors('tercero.tipo_doc');
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion(['regimen' => '09']))->assertUnprocessable()->assertJsonValidationErrors('regimen');
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion(['serie_id' => $this->p002->id]))->assertUnprocessable()->assertJsonValidationErrors('serie_id');
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $this->retencion(['documentos' => [['tipo_doc' => '01', 'num_doc' => 'F001 34', 'fecha_emision' => '2026-11-01', 'imp_total' => 10]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('documentos.0.num_doc');

        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($v)->getJson('/api/v1/retenciones')->assertForbidden();
        $c = User::factory()->create();
        $c->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($c)->getJson('/api/v1/retenciones')->assertOk();
        $this->actingAs($c)->postJson('/api/v1/retenciones', $this->retencion())->assertForbidden();
    }

    public function test_idempotencia(): void
    {
        $body = $this->retencion();
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $body)->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/retenciones', $body)->assertCreated();
        $this->assertSame(1, ComprobanteRetencion::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->r002->fresh()->correlativo_actual);
    }
}
