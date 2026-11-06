<?php

namespace Tests\Feature\Empresas;

use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Empresas\Services\SerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EstablecimientosSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true]);
    }

    private function h(?Empresa $e = null): array
    {
        return ['X-Empresa-Id' => (string) ($e ?? $this->empresa)->id];
    }

    /** Respuestas con la forma REAL capturada en la Etapa 1 (01S.03 y 01R.02). */
    private function fakeApi(): void
    {
        Http::fake([
            "{$this->base}/sucursales" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
                ['id' => 65, 'nombre' => 'Sucursal Principal', 'cod_local' => '0003', 'direccion' => 'AV. PRINCIPAL 123, LIMA', 'ubigeo' => '150101', 'telefono' => null, 'email' => null, 'es_principal' => true, 'activo' => true, 'total_series' => 0],
            ]]),
            "{$this->base}/series" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
                ['id' => 47, 'tipo' => 'factura', 'tipo_documento' => '01', 'serie' => 'F001', 'correlativo' => 962, 'siguiente_numero' => 'F001-963', 'sucursal' => ['id' => 14, 'nombre' => 'Sucursal Principal', 'cod_local' => '0001'], 'activo' => true],
                ['id' => 48, 'tipo' => 'boleta', 'tipo_documento' => '03', 'serie' => 'B001', 'correlativo' => 274, 'siguiente_numero' => 'B001-275', 'sucursal' => ['id' => 14, 'nombre' => 'Sucursal Principal', 'cod_local' => '0001'], 'activo' => true],
            ]]),
        ]);
    }

    public function test_importa_establecimientos_y_series_con_su_ultimo_correlativo(): void
    {
        $this->fakeApi();
        $this->actingAs($this->admin)->withHeaders($this->h())->postJson('/api/v1/establecimientos/sincronizar')
            ->assertOk()->assertJsonPath('data', ['establecimientos' => 1, 'series' => 2]);

        // La sucursal 14 (cod_local 0001) solo venía embebida en las series: se creó igual.
        $this->assertSame(['0001', '0003'], Establecimiento::deEmpresa($this->empresa->id)->orderBy('cod_local')->pluck('cod_local')->all());
        $f001 = Serie::deEmpresa($this->empresa->id)->where('serie', 'F001')->sole();
        $this->assertSame(962, $f001->correlativo_actual);
        $this->assertSame('F001-963', $f001->siguienteNumero());

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', 'pk_test_123456'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET'); // importar es solo lectura
    }

    public function test_reimportar_nunca_retrocede_el_correlativo_local(): void
    {
        $this->fakeApi();
        $this->actingAs($this->admin)->withHeaders($this->h())->postJson('/api/v1/establecimientos/sincronizar')->assertOk();
        Serie::deEmpresa($this->empresa->id)->where('serie', 'F001')->update(['correlativo_actual' => 1000]);

        $this->actingAs($this->admin)->withHeaders($this->h())->postJson('/api/v1/establecimientos/sincronizar')->assertOk();
        $this->assertSame(1000, Serie::deEmpresa($this->empresa->id)->where('serie', 'F001')->value('correlativo_actual'));
        $this->assertSame(2, Serie::deEmpresa($this->empresa->id)->count());
    }

    public function test_reserva_de_correlativos_secuencial(): void
    {
        $serie = Serie::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '01', 'serie' => 'F001', 'correlativo_actual' => 962]);
        $svc = app(SerieService::class);
        $this->assertSame([963, 964, 965], [$svc->reservarSiguiente($serie->id), $svc->reservarSiguiente($serie->id), $svc->reservarSiguiente($serie->id)]);

        $serie->update(['activo' => false]);
        $this->expectExceptionMessage('desactivada');
        $svc->reservarSiguiente($serie->id);
    }

    public function test_crea_serie_en_la_api_con_la_estructura_de_postman(): void
    {
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 65]);
        Http::fake([
            "{$this->base}/series" => Http::sequence()
                ->push(['estado' => 'exito', 'mensaje' => 'Series creadas'], 201)
                ->push(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [['id' => 300, 'tipo_documento' => '07', 'serie' => 'FC02', 'correlativo' => 0, 'activo' => true]]]),
        ]);

        $this->actingAs($this->admin)->withHeaders($this->h())->postJson('/api/v1/series', [
            'establecimiento_id' => $est->id, 'tipo_documento' => '07', 'serie' => 'FC02',
        ])->assertCreated()->assertJsonPath('data.siguiente_numero', 'FC02-1')->assertJsonPath('data.sincronizado', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['series' => [['tipo' => 'nota_credito', 'serie' => 'FC02', 'sucursal_id' => 65, 'correlativo_inicial' => 0]]]);
    }

    public function test_serie_predeterminada_es_local_y_unica_por_tipo_y_familia(): void
    {
        $mk = fn ($t, $n, $sync = true) => Serie::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => $t, 'serie' => $n, 'api_serie_id' => $sync ? crc32($n) : null]);
        [$f001, $f002, $fc01, $fc02, $bc01] = [$mk('01', 'F001'), $mk('01', 'F002'), $mk('07', 'FC01'), $mk('07', 'FC02'), $mk('07', 'BC01')];
        $f001->update(['predeterminada' => true]);
        $bc01->update(['predeterminada' => true]);
        Http::fake();

        $this->actingAs($this->admin)->withHeaders($this->h())->putJson("/api/v1/series/{$f002->id}", ['predeterminada' => true])
            ->assertOk()->assertJsonPath('data.predeterminada', true);
        $this->assertFalse($f001->fresh()->predeterminada);
        $this->actingAs($this->admin)->withHeaders($this->h())->putJson("/api/v1/series/{$fc02->id}", ['predeterminada' => true])->assertOk();
        $this->assertTrue($bc01->fresh()->predeterminada); // otra familia (B) no se toca
        Http::assertNothingSent(); // preferencia local: no llama a la API
        $this->assertDatabaseHas('audit_logs', ['evento' => 'serie.actualizada', 'auditable_id' => $f002->id]);

        $sinApi = $mk('01', 'F003', false);
        $this->actingAs($this->admin)->withHeaders($this->h())->putJson("/api/v1/series/{$sinApi->id}", ['predeterminada' => true])->assertStatus(422);
    }

    public function test_valida_prefijo_de_serie_segun_tipo(): void
    {
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 65]);
        Http::fake();
        $this->actingAs($this->admin)->withHeaders($this->h())->postJson('/api/v1/series', ['establecimiento_id' => $est->id, 'tipo_documento' => '01', 'serie' => 'B099'])
            ->assertStatus(422)->assertJsonValidationErrors('serie');
        Http::assertNothingSent();
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $otra = Empresa::factory()->create();
        $ajena = Serie::create(['empresa_id' => $otra->id, 'tipo_documento' => '01', 'serie' => 'F777']);
        Serie::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '01', 'serie' => 'F001']);
        Http::fake();

        // No ve series de otra empresa…
        $this->actingAs($this->admin)->withHeaders($this->h())->getJson('/api/v1/establecimientos')
            ->assertOk()->assertJsonCount(1, 'data.series_sin_establecimiento')->assertJsonPath('data.series_sin_establecimiento.0.serie', 'F001');
        // …ni puede modificarlas aunque conozca el id.
        $this->actingAs($this->admin)->withHeaders($this->h())->putJson("/api/v1/series/{$ajena->id}", ['activo' => false])->assertNotFound();
        $this->assertTrue($ajena->fresh()->activo);
        // …ni puede declararse en una empresa a la que no pertenece.
        $this->actingAs($this->admin)->withHeaders($this->h($otra))->getJson('/api/v1/establecimientos')->assertForbidden();
        // Sin header no hay empresa activa.
        $this->flushHeaders()->actingAs($this->admin)->getJson('/api/v1/establecimientos')->assertStatus(400);
    }

    public function test_vendedor_ve_pero_no_administra(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        Http::fake();
        $this->actingAs($vendedor)->withHeaders($this->h())->getJson('/api/v1/establecimientos')->assertOk();
        $this->actingAs($vendedor)->withHeaders($this->h())->postJson('/api/v1/establecimientos/sincronizar')->assertForbidden();
    }
}
