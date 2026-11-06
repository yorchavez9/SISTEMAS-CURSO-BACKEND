<?php

namespace Tests\Feature\Facturacion;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Serie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Impresión en ticket 80/58 mm, A5 y A4 (formatos de la API validados en beta). */
class ImpresionTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Comprobante $c;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'P', 'cod_local' => '0000', 'api_sucursal_id' => 1]);
        $s = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '03', 'serie' => 'B002', 'api_serie_id' => 9]);
        $this->c = Comprobante::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'serie_id' => $s->id, 'tipo_documento' => '03',
            'serie' => 'B002', 'correlativo' => 1, 'fecha_emision' => '2026-11-06', 'tipo_operacion' => '0101', 'moneda' => 'PEN', 'forma_pago' => 'contado',
            'cliente_tipo_doc' => '0', 'cliente_num_doc' => '99999999', 'cliente_razon_social' => 'CONSUMIDOR FINAL', 'total' => 10, 'estado' => 'pendiente_resumen',
            'api_id' => 522, 'payload' => []]);
    }

    public function test_imprime_ticket_80_desde_la_api_inline(): void
    {
        Http::fake(["{$this->base}/boletas/522/pdf*" => Http::response('%PDF-1.7 ticket', 200, ['Content-Type' => 'application/pdf'])]);
        $r = $this->actingAs($this->admin)->get("/api/v1/comprobantes/{$this->c->id}/imprimir/ticket-80")->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $r->headers->get('Content-Disposition'));
        Http::assertSent(fn (Request $q) => str_contains($q->url(), '/boletas/522/pdf') && $q['format'] === 'ticket-80');
        $this->actingAs($this->admin)->getJson("/api/v1/comprobantes/{$this->c->id}")->assertJsonPath('data.imprimible', true);
    }

    public function test_formato_invalido_sin_api_o_rechazado(): void
    {
        Http::fake();
        $this->actingAs($this->admin)->get("/api/v1/comprobantes/{$this->c->id}/imprimir/ticket-99")->assertNotFound();
        $this->c->update(['estado' => 'rechazado']);
        $this->actingAs($this->admin)->getJson("/api/v1/comprobantes/{$this->c->id}/imprimir/a5")->assertStatus(409);
        $this->c->update(['estado' => 'por_enviar', 'api_id' => null]);
        $this->actingAs($this->admin)->getJson("/api/v1/comprobantes/{$this->c->id}/imprimir/a5")->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_respuesta_que_no_es_pdf_da_502(): void
    {
        Http::fake(["{$this->base}/boletas/522/pdf*" => Http::response(['estado' => 'error', 'mensaje' => 'x'], 404)]);
        $this->actingAs($this->admin)->getJson("/api/v1/comprobantes/{$this->c->id}/imprimir/a4")->assertStatus(502);
    }
}
