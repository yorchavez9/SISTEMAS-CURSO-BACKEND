<?php

namespace Tests\Feature\Empresas;

use App\Models\ApiSolicitud;
use App\Models\AuditLog;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerfilApiTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648', 'razon_social' => 'ANTES SAC']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456',
            'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tax_regime' => 'mype', 'tasa_igv_vigente' => 18]]);
    }

    /** Respuesta real de GET /empresa (beta, 2026-11-07), con los datos que se piden cambiar aplicados. */
    private function perfil(array $o = []): array
    {
        return ['estado' => 'exito', 'datos' => $o + [
            'id' => 10, 'ruc' => '20161515648', 'razon_social' => 'MI EMPRESA SAC', 'nombre_comercial' => 'MI EMPRESA', 'direccion' => 'AV. PRINCIPAL 456',
            'ubigeo' => '150101', 'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'LIMA', 'sol_user' => 'MODDATOS', 'tiene_sol_pass' => true,
            'client_id' => 'test-85e5', 'tiene_client_secret' => true, 'entorno' => 'beta', 'plan' => 'pro', 'tax_regime' => 'mype',
            'igv_rate_override' => null, 'nrus_categoria' => null, 'tasa_igv_vigente' => 18, 'max_documentos_mes' => 500, 'documentos_este_mes' => 130,
            'logo_url' => '/storage/logos/20161515648/logo.png?v=1', 'tiene_certificado' => true, 'telefonos' => ['+51 999 888 777'],
            'emails' => ['contacto@miempresa.pe'], 'cuentas_bancarias' => [], 'billeteras_digitales' => [],
            'mensaje_agradecimiento' => 'Gracias por su compra', 'mensaje_promocional' => null,
        ]];
    }

    public function test_lee_el_perfil_sin_exponer_secretos_y_con_logo_absoluto(): void
    {
        Http::fake(["{$this->base}/empresa" => Http::response($this->perfil())]);
        $r = $this->actingAs($this->admin)->getJson("/api/v1/empresas/{$this->empresa->id}/perfil-api")->assertOk()
            ->assertJsonPath('data.logo_url', 'https://sunat.kodevo.es/storage/logos/20161515648/logo.png?v=1')
            ->assertJsonPath('data.tiene_certificado', true)->assertJsonPath('data.tiene_sol_pass', true)->json('data');
        $this->assertArrayNotHasKey('sol_pass', $r);
        $this->assertArrayNotHasKey('client_secret', $r);
    }

    public function test_actualiza_datos_pdf_y_medios_de_pago_y_sincroniza_lo_local(): void
    {
        Http::fake([
            "{$this->base}/empresa" => fn (Request $req) => Http::response($req->method() === 'PUT'
                ? ['estado' => 'exito', 'mensaje' => 'Empresa actualizada']
                : $this->perfil(['razon_social' => 'NUEVA RAZON SAC', 'mensaje_promocional' => '10% de descuento'])),
        ]);
        $body = [
            'razon_social' => 'NUEVA RAZON SAC', 'direccion' => 'AV. NUEVA 123', 'telefonos' => ['+51 911 222 333', '01 4445566'], 'emails' => ['ventas@nueva.pe'],
            'cuentas_bancarias' => [['banco' => 'BCP', 'tipo_cuenta' => 'Corriente', 'moneda' => 'PEN', 'numero' => '191-1234567-0-12', 'cci' => '00219100123456701255']],
            'billeteras_digitales' => [['tipo' => 'yape', 'numero' => '911222333', 'titular' => 'NUEVA RAZON']],
            'mensaje_agradecimiento' => 'Gracias por su preferencia', 'mensaje_promocional' => '10% de descuento',
            'sol_user' => 'MODDATOS', 'sol_pass' => 'secreta123', 'client_secret' => '',
        ];
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/perfil-api", $body)->assertOk()
            ->assertJsonPath('data.razon_social', 'NUEVA RAZON SAC')->assertJsonPath('data.mensaje_promocional', '10% de descuento');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['sol_pass'] === 'secreta123'
            && ! array_key_exists('client_secret', $r->data()) // vacío = no cambiar
            && $r['cuentas_bancarias'][0]['banco'] === 'BCP' && $r['billeteras_digitales'][0]['tipo'] === 'yape');
        $this->empresa->refresh();
        $this->assertSame(['NUEVA RAZON SAC', 'AV. NUEVA 123', '+51 911 222 333', 'ventas@nueva.pe'],
            [$this->empresa->razon_social, $this->empresa->direccion, $this->empresa->telefono, $this->empresa->email]);

        // Ni el historial de llamadas ni la auditoría guardan la Clave SOL.
        $this->assertSame('***', ApiSolicitud::where('operacion', 'perfil_actualizar')->sole()->payload['sol_pass']);
        $log = AuditLog::where('evento', 'empresa.perfil_api')->sole();
        $this->assertStringNotContainsString('secreta123', json_encode($log->toArray()));
    }

    public function test_cambio_de_regimen_actualiza_la_tasa_que_usa_el_calculo(): void
    {
        Http::fake(["{$this->base}/empresa" => fn (Request $req) => Http::response($req->method() === 'PUT'
            ? ['estado' => 'exito'] : $this->perfil(['tax_regime' => 'rus', 'nrus_categoria' => 1, 'tasa_igv_vigente' => 0]))]);
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/perfil-api", ['tax_regime' => 'rus'])
            ->assertUnprocessable()->assertJsonValidationErrors('nrus_categoria');
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/perfil-api", ['tax_regime' => 'rus', 'nrus_categoria' => 1])->assertOk();
        $this->assertEquals(['tax_regime' => 'rus', 'tasa_igv_vigente' => 0], array_intersect_key($this->empresa->credencialApi()->remoto, array_flip(['tax_regime', 'tasa_igv_vigente'])));
    }

    public function test_errores_de_la_api_se_muestran_en_el_campo(): void
    {
        Http::fake(["{$this->base}/empresa" => Http::response(['estado' => 'error', 'mensaje' => 'Error de validación', 'errores' => ['ubigeo' => ['El ubigeo no existe.']]], 422)]);
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/perfil-api", ['ubigeo' => '999999'])
            ->assertUnprocessable()->assertJsonPath('errors.ubigeo.0', 'El ubigeo no existe.');
        $this->assertSame('ANTES SAC', $this->empresa->fresh()->razon_social);
    }

    public function test_sube_logo_y_certificado_como_multipart_sin_guardar_la_contrasena(): void
    {
        Http::fake([
            "{$this->base}/empresa/logo" => Http::response(['estado' => 'exito', 'mensaje' => 'Logo actualizado']),
            "{$this->base}/empresa/certificado" => Http::response(['estado' => 'exito', 'mensaje' => 'Certificado actualizado']),
            "{$this->base}/empresa" => Http::response($this->perfil()),
        ]);
        $this->actingAs($this->admin)->post("/api/v1/empresas/{$this->empresa->id}/perfil-api/logo",
            ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)], ['Accept' => 'application/json'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/empresa/logo') && $r->isMultipart() && $r->hasFile('logo'));

        $pfx = UploadedFile::fake()->createWithContent('certificado.pfx', 'PFX-BINARIO');
        $this->actingAs($this->admin)->post("/api/v1/empresas/{$this->empresa->id}/perfil-api/certificado",
            ['certificado' => $pfx, 'contrasena_certificado' => 'claveCert2026'], ['Accept' => 'application/json'])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/empresa/certificado') && $r->isMultipart()
            && $r->hasFile('certificado', 'PFX-BINARIO') && collect($r->data())->firstWhere('name', 'contrasena_certificado')['contents'] === 'claveCert2026');
        $this->assertSame('***', ApiSolicitud::where('operacion', 'perfil_certificado')->sole()->payload['contrasena_certificado']);

        // Solo .pfx / .p12
        $this->actingAs($this->admin)->post("/api/v1/empresas/{$this->empresa->id}/perfil-api/certificado",
            ['certificado' => UploadedFile::fake()->create('cert.txt', 1), 'contrasena_certificado' => 'x'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('certificado');
    }

    public function test_un_vendedor_no_puede_cambiar_la_configuracion(): void
    {
        Http::fake();
        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($v)->putJson("/api/v1/empresas/{$this->empresa->id}/perfil-api", ['mensaje_agradecimiento' => 'x'])->assertForbidden();
        $this->actingAs($v)->post("/api/v1/empresas/{$this->empresa->id}/perfil-api/certificado",
            ['certificado' => UploadedFile::fake()->createWithContent('c.pfx', 'x'), 'contrasena_certificado' => 'x'], ['Accept' => 'application/json'])->assertForbidden();
        Http::assertNothingSent();
    }
}
