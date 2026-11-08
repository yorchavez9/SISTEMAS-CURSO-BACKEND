<?php

namespace Tests\Feature\Empresas;

use App\Models\AuditLog;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsultaDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private string $base;
    private string $deco = 'https://api.decolecta.com';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456',
            'api_secret' => 'sk_test_123456', 'verificacion_ok' => true]);
    }

    private function usarDecolecta(string $token = 'sk_deco_123456789'): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/consulta-documentos", ['proveedor' => 'decolecta', 'token' => $token])
            ->assertOk()->assertJsonPath('data.proveedor', 'decolecta')->assertJsonPath('data.token_final', '••••6789');
    }

    public function test_token_cifrado_nunca_se_devuelve_y_se_audita_sin_valor(): void
    {
        $this->usarDecolecta();
        $crudo = DB::table('empresas')->where('id', $this->empresa->id)->value('consulta_token');
        $this->assertNotSame('sk_deco_123456789', $crudo); // cifrado con APP_KEY
        $this->assertSame('sk_deco_123456789', $this->empresa->fresh()->consulta_token);
        $r = $this->actingAs($this->admin)->getJson("/api/v1/empresas/{$this->empresa->id}")->assertOk();
        $this->assertStringNotContainsString('sk_deco', $r->getContent());
        $this->assertStringNotContainsString('sk_deco', json_encode(AuditLog::where('evento', 'empresa.consulta_documentos')->sole()->toArray()));

        // Cambiar de proveedor sin escribir token conserva el actual.
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/consulta-documentos", ['proveedor' => 'decolecta'])->assertOk();
        $this->assertSame('sk_deco_123456789', $this->empresa->fresh()->consulta_token);
    }

    public function test_decolecta_ruc_y_dni_con_el_formato_de_su_documentacion(): void
    {
        $this->usarDecolecta();
        Http::fake([
            "{$this->deco}/v1/sunat/ruc*" => Http::response(['razon_social' => 'REXTIE S.A.C.', 'numero_documento' => '20601030013', 'estado' => 'ACTIVO',
                'condicion' => 'HABIDO', 'direccion' => 'AV. JOSE GALVEZ BARRENECHEA NRO 566 INT. 101 URB. CORPAC ', 'ubigeo' => '150131',
                'distrito' => 'SAN ISIDRO', 'provincia' => 'LIMA', 'departamento' => 'LIMA']),
            "{$this->deco}/v1/reniec/dni*" => Http::response(['first_name' => 'ROXANA KARINA', 'first_last_name' => 'DELGADO', 'second_last_name' => 'HUAMANI',
                'full_name' => 'DELGADO HUAMANI ROXANA KARINA', 'document_number' => '46027897']),
        ]);
        $this->actingAs($this->admin)->getJson('/api/v1/clientes/consultar-documento?tipo=6&numero=20601030013')->assertOk()
            ->assertJsonPath('data.fuente', 'decolecta')->assertJsonPath('data.cliente.razon_social', 'REXTIE S.A.C.')
            ->assertJsonPath('data.cliente.direccion', 'AV. JOSE GALVEZ BARRENECHEA NRO 566 INT. 101 URB. CORPAC LIMA - LIMA - SAN ISIDRO');
        $this->actingAs($this->admin)->getJson('/api/v1/clientes/consultar-documento?tipo=1&numero=46027897')->assertOk()
            ->assertJsonPath('data.cliente.razon_social', 'DELGADO HUAMANI ROXANA KARINA');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), "{$this->deco}/v1/reniec/dni") && $r['numero'] === '46027897'
            && $r->hasHeader('Authorization', 'Bearer sk_deco_123456789'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'buscar-documento'));
    }

    public function test_si_decolecta_falla_usa_la_api_de_facturacion_como_respaldo(): void
    {
        $this->usarDecolecta();
        Http::fake([
            "{$this->deco}/*" => Http::response(['message' => 'Unauthenticated'], 401),
            "{$this->base}/buscar-documento*" => Http::response(['estado' => 'exito', 'datos' => ['tipo_doc' => '6', 'num_doc' => '20131312955',
                'razon_social' => 'SUNAT', 'direccion' => 'AV. GARCILASO 1472', 'fuente' => 'local']]),
        ]);
        $this->actingAs($this->admin)->getJson('/api/v1/clientes/consultar-documento?tipo=6&numero=20131312955')->assertOk()
            ->assertJsonPath('data.fuente', 'local (respaldo)')->assertJsonPath('data.cliente.razon_social', 'SUNAT');

        $this->actingAs($this->admin)->postJson("/api/v1/empresas/{$this->empresa->id}/consulta-documentos/probar", ['proveedor' => 'decolecta'])
            ->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.mensaje', 'No se pudo consultar: Decolecta rechazó el token (revísalo en Configuración → Credenciales API).');
    }

    public function test_validaciones_y_permisos(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/consulta-documentos", ['proveedor' => 'decolecta'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->actingAs($this->admin)->putJson("/api/v1/empresas/{$this->empresa->id}/consulta-documentos", ['proveedor' => 'otro'])
            ->assertUnprocessable()->assertJsonValidationErrors('proveedor');
        $v = User::factory()->create();
        $v->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($v)->getJson("/api/v1/empresas/{$this->empresa->id}/consulta-documentos")->assertForbidden();
    }
}
