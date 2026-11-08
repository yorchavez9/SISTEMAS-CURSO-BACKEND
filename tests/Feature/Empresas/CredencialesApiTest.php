<?php

namespace Tests\Feature\Empresas;

use App\Models\AuditLog;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CredencialesApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'pk_test_ABCDEFGH1234';
    private const SECRET = 'sk_test_SUPERSECRETO9876';

    private Empresa $empresa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create(['ruc' => '20100000001']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
    }

    /** Simula la API con el formato documentado {estado, mensaje, datos}. */
    private function fakeApi(string $ruc = '20100000001', ?string $entorno = 'beta', int $status = 200): void
    {
        $base = rtrim(config('services.sunat_api.base_url'), '/');
        Http::fake([
            "$base/empresa" => $status === 200
                ? Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => array_filter(['ruc' => $ruc, 'razon_social' => 'EMPRESA DEMO', 'entorno' => $entorno, 'tax_regime' => 'general', 'tasa_igv_vigente' => 18], fn ($v) => $v !== null)])
                : Http::response(['estado' => 'error', 'mensaje' => 'Credenciales inválidas.'], $status),
            "$base/suscripcion" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => ['plan_slug' => 'pro']]),
            "$base/suscripcion/uso" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => ['documents_month' => 3, 'limite' => 600]]),
        ]);
    }

    private function guardar(array $body, string $entorno = 'pruebas', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)
            ->putJson("/api/v1/empresas/{$this->empresa->id}/credenciales-api/$entorno", $body);
    }

    public function test_guarda_cifrado_y_nunca_devuelve_los_secretos(): void
    {
        $this->fakeApi();
        $res = $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET]);

        $res->assertOk()
            ->assertJsonPath('data.configurada', true)
            ->assertJsonPath('data.api_key_mascara', '••••••••1234')
            ->assertJsonPath('data.verificacion_ok', true);
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());
        $this->assertStringNotContainsString(self::KEY, $res->getContent());

        $raw = DB::table('empresa_api_credenciales')->first();
        $this->assertNotSame(self::KEY, $raw->api_key);
        $this->assertStringNotContainsString(self::SECRET, $raw->api_secret);
        $cred = EmpresaApiCredencial::first();
        $this->assertSame(self::SECRET, $cred->api_secret);

        $index = $this->actingAs($this->admin)->getJson("/api/v1/empresas/{$this->empresa->id}/credenciales-api");
        $index->assertOk()->assertJsonCount(2, 'data.entornos');
        $this->assertStringNotContainsString(self::SECRET, $index->getContent());
    }

    public function test_envia_los_headers_de_autenticacion_de_la_coleccion(): void
    {
        $this->fakeApi();
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/empresa')
            && $r->hasHeader('X-Api-Key', self::KEY)
            && $r->hasHeader('X-Api-Secret', self::SECRET)
            && $r->hasHeader('Accept', 'application/json'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET'); // la verificación es solo lectura
    }

    public function test_el_secreto_es_obligatorio_al_crear_y_opcional_al_actualizar(): void
    {
        $this->fakeApi();
        $this->guardar(['api_key' => self::KEY])->assertStatus(422)->assertJsonValidationErrors('api_secret');

        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET])->assertOk();
        $this->guardar(['api_key' => 'pk_test_NUEVA5678', 'api_secret' => ''])->assertOk()->assertJsonPath('data.api_key_mascara', '••••••••5678');

        $cred = EmpresaApiCredencial::first();
        $this->assertSame('pk_test_NUEVA5678', $cred->api_key);
        $this->assertSame(self::SECRET, $cred->api_secret);
    }

    public function test_rechaza_credenciales_de_otro_ruc(): void
    {
        $this->fakeApi(ruc: '20999999999');
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('data.verificacion_ok', false)
            ->assertJsonPath('data.verificacion_mensaje', 'Las credenciales pertenecen al RUC 20999999999, no a 20100000001.');
    }

    public function test_credenciales_de_produccion_registradas_como_pruebas_no_se_validan(): void
    {
        $this->fakeApi(entorno: 'production');
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET], 'pruebas')
            ->assertOk()->assertJsonPath('data.verificacion_ok', false);

        $this->fakeApi(entorno: 'production');
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET], 'produccion')
            ->assertOk()->assertJsonPath('data.verificacion_ok', true);
    }

    public function test_credenciales_rechazadas_por_la_api(): void
    {
        $this->fakeApi(status: 401);
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('data.verificacion_ok', false)
            ->assertJsonPath('data.verificacion_mensaje', 'Credenciales rechazadas por la API: Credenciales inválidas.');
    }

    public function test_solo_administradores_de_la_empresa(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $ajeno = User::factory()->create();

        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET], user: $vendedor)->assertForbidden();
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET], user: $ajeno)->assertForbidden();
        $this->actingAs($ajeno)->getJson("/api/v1/empresas/{$this->empresa->id}/credenciales-api")->assertForbidden();
    }

    public function test_sin_sesion_no_hay_acceso(): void
    {
        $this->putJson("/api/v1/empresas/{$this->empresa->id}/credenciales-api/pruebas", [])->assertUnauthorized();
        $this->getJson("/api/v1/empresas/{$this->empresa->id}/credenciales-api")->assertUnauthorized();
    }

    public function test_no_se_activa_produccion_sin_credenciales_de_produccion_verificadas(): void
    {
        $url = "/api/v1/empresas/{$this->empresa->id}";
        $this->actingAs($this->admin)->putJson($url, ['entorno_activo' => 'produccion'])
            ->assertStatus(422)->assertJsonValidationErrors('entorno_activo');

        $this->fakeApi(entorno: 'production');
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET], 'produccion')->assertJsonPath('data.verificacion_ok', true);
        $this->actingAs($this->admin)->putJson($url, ['entorno_activo' => 'produccion'])
            ->assertOk()->assertJsonPath('data.entorno_activo', 'produccion');
    }

    public function test_entorno_invalido_no_existe(): void
    {
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET], 'staging')->assertNotFound();
    }

    public function test_la_auditoria_no_guarda_secretos(): void
    {
        $this->fakeApi();
        $this->guardar(['api_key' => self::KEY, 'api_secret' => self::SECRET])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/v1/empresas/{$this->empresa->id}/credenciales-api/pruebas")->assertOk();

        $logs = AuditLog::pluck('evento')->all();
        $this->assertEqualsCanonicalizing(['credenciales_api.creadas', 'credenciales_api.verificadas', 'credenciales_api.eliminadas'], $logs);
        $dump = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString(self::KEY, $dump);
        $this->assertSame(0, EmpresaApiCredencial::count());
    }

    public function test_url_base_propia_se_guarda_y_se_usa_en_todas_las_llamadas(): void
    {
        $propia = 'https://api-facturacion.miempresa.pe/api/v1';
        Http::fake([
            "$propia/empresa" => Http::response(['estado' => 'exito', 'datos' => ['ruc' => '20100000001', 'razon_social' => 'EMPRESA DEMO', 'entorno' => 'beta']]),
            "$propia/suscripcion*" => Http::response(['estado' => 'exito', 'datos' => []]),
        ]);
        $url = "/api/v1/empresas/{$this->empresa->id}/credenciales-api/pruebas";
        $this->actingAs($this->admin)->putJson($url, ['api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'base_url' => "$propia/"])
            ->assertOk()->assertJsonPath('data.base_url', $propia)->assertJsonPath('data.verificacion_ok', true);
        Http::assertSent(fn (Request $r) => $r->url() === "$propia/empresa");

        // El cliente de la empresa (emisión, consultas…) usa esa URL.
        $cliente = app(\App\Integrations\SunatApi\SunatApiClientFactory::class)->paraEmpresa($this->empresa->fresh());
        $cliente->get('/empresa');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), $propia));

        // Cambiar solo la URL (sin reescribir API Key ni Secret) invalida la verificación anterior; vacía vuelve a la del .env.
        $this->actingAs($this->admin)->putJson($url, ['base_url' => '', 'verificar' => false])->assertOk()
            ->assertJsonPath('data.base_url', null)->assertJsonPath('data.verificacion_ok', null);
        $this->assertSame('pk_test_123456', $this->empresa->credencialApi('pruebas')->api_key);
    }

    public function test_url_base_debe_ser_https_y_sin_parametros(): void
    {
        $url = "/api/v1/empresas/{$this->empresa->id}/credenciales-api/pruebas";
        foreach (['http://sunat.kodevo.es/api/v1', 'no-es-url', 'https://sunat.kodevo.es/api/v1?x=1'] as $mala) {
            $this->actingAs($this->admin)->putJson($url, ['api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'base_url' => $mala])
                ->assertUnprocessable()->assertJsonValidationErrors('base_url');
        }
        // Sin credenciales previas el API Key sigue siendo obligatorio.
        $this->actingAs($this->admin)->putJson($url, ['api_secret' => 'sk_test_123456'])->assertUnprocessable()->assertJsonValidationErrors('api_key');
    }
}
