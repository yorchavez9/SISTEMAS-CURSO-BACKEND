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
use App\Models\Stock;
use App\Models\User;
use App\Models\Venta;
use App\Modules\Facturacion\Services\BajaService;
use App\Modules\Facturacion\Services\ResumenService;
use App\Modules\Inventario\Services\InventarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BajasTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;
    private Serie $f001;
    private Serie $b001;
    private Serie $fc01;
    private Cliente $cliente;
    private Producto $mouse;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456', 'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 14]);
        $s = fn ($t, $n) => Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => $t, 'serie' => $n, 'api_serie_id' => crc32($n)]);
        [$this->f001, $this->b001, $this->fc01] = [$s('01', 'F001'), $s('03', 'B001'), $s('07', 'FC01')];
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT']);
        $this->mouse = Producto::create(['empresa_id' => $this->empresa->id, 'codigo' => 'P001', 'descripcion' => 'MOUSE', 'unidad' => 'NIU', 'precio_venta' => 35.4, 'tip_afe_igv' => '10']);
        app(InventarioService::class)->registrar($this->empresa, $this->admin, app(InventarioService::class)->almacenPrincipal($this->empresa->id, $est->id), 'entrada',
            [['producto_id' => $this->mouse->id, 'cantidad' => 10, 'costo_unitario' => 20]], 'INI', null);
    }

    private function emitida(string $tipo = '01', int $apiId = 900): Comprobante
    {
        $this->actingAs($this->admin)->postJson('/api/v1/ventas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => $tipo,
            'serie_id' => $tipo === '01' ? $this->f001->id : $this->b001->id, 'cliente_id' => $this->cliente->id,
            'items' => [['producto_id' => $this->mouse->id, 'cantidad' => 2]]])->assertCreated();
        $c = Comprobante::withoutGlobalScopes()->latest('id')->first();
        $c->update(['estado' => 'aceptado', 'api_id' => $apiId]);

        return $c;
    }

    private function stock(): string
    {
        return (string) Stock::withoutGlobalScopes()->where('producto_id', $this->mouse->id)->value('cantidad');
    }

    public function test_ra_de_factura_con_fecha_comunicacion_explicita_y_efectos_al_aceptarse(): void
    {
        $f = $this->emitida();
        $this->assertSame('8.0000', $this->stock());
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'Error en datos del cliente'])
            ->assertCreated()->assertJsonPath('data.tipo', 'RA');
        $com = Comunicacion::withoutGlobalScopes()->sole();
        $hoy = now('America/Lima')->toDateString();
        // Estructura del ejemplo RA-01 de Postman + fecha_comunicacion SIEMPRE explícita (corrige el defecto UTC H-1).
        $this->assertEquals(['fecha_generacion' => $hoy, 'fecha_comunicacion' => $hoy, 'enviar_automatico' => true,
            'detalles' => [['tipo_documento' => '01', 'serie' => 'F001', 'correlativo' => '1', 'motivo' => 'Error en datos del cliente']]], $com->payload);

        // Respuestas reales (Etapa 1: RA-01 y su /estado)
        Http::fake([
            "{$this->base}/anulaciones" => Http::response(['estado' => 'exito', 'mensaje' => 'Comunicación de baja encolada para envío a SUNAT.',
                'datos' => ['id_anulacion' => 39, 'identifier' => 'RA-20261105-001', 'correlativo' => '001', 'estado_sunat' => 'enviado']], 201),
            "{$this->base}/anulaciones/39/estado" => Http::response(['estado' => 'exito', 'datos' => ['id_anulacion' => 39, 'identifier' => 'RA-20261105-001',
                'ticket' => '1793930873684', 'estado_sunat' => 'aceptado', 'codigo_sunat' => '0', 'descripcion_sunat' => 'La Comunicacion de baja RA-20261105-001, ha sido aceptada']]),
        ]);
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->enviarComunicacion(\App\Models\Comunicacion::withoutGlobalScopes()->find($com->id));
        $this->assertSame('enviado', $com->fresh()->estado);
        $this->assertSame('aceptado', $f->fresh()->estado); // aún no hay efectos

        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->actualizarComunicacion(\App\Models\Comunicacion::withoutGlobalScopes()->find($com->id), forzar: true);
        $com->refresh();
        $this->assertSame(['aceptado', 'RA-20261105-001', '0'], [$com->estado, $com->identifier, $com->sunat_codigo]);
        $this->assertSame('anulado', $f->fresh()->estado);
        $v = Venta::withoutGlobalScopes()->find($f->venta_id);
        $this->assertTrue($v->anulada_por_baja);
        $this->assertSame('0.00', (string) $v->saldo);
        $this->assertSame('10.0000', $this->stock()); // mercadería reingresada
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['fecha_comunicacion'] === $hoy);

        // Idempotente: volver a aplicar efectos no duplica el reingreso
        app(BajaService::class)->aplicarEfectos($com);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_rc_de_anulacion_para_boletas(): void
    {
        $b = $this->emitida('03', 507);
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$b->id], 'motivo' => 'Cliente solicita anulación'])
            ->assertCreated()->assertJsonPath('data.tipo', 'RC');
        $this->assertEquals(['fecha_resumen' => now('America/Lima')->toDateString(), 'anular' => [['id' => 507, 'motivo' => 'Cliente solicita anulación']], 'enviar_automatico' => true],
            Comunicacion::withoutGlobalScopes()->sole()->payload); // ejemplo RC-02
    }

    public function test_validaciones(): void
    {
        $f = $this->emitida();
        $b = $this->emitida('03', 508);
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id, $b->id], 'motivo' => 'Mezcla'])->assertStatus(422);

        $f->update(['estado' => 'enviado']);
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'No aceptado'])->assertStatus(422);
        $f->update(['estado' => 'aceptado', 'fecha_emision' => now('America/Lima')->subDays(8)->toDateString()]);
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'Fuera de plazo'])
            ->assertStatus(422)->assertJsonPath('errors.comprobante_ids.0', 'F001-1 superó el plazo de 7 días para la baja: emite una nota de crédito 01.');

        $f->update(['fecha_emision' => now('America/Lima')->toDateString()]);
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'Primera baja'])->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'Segunda baja'])
            ->assertStatus(422)->assertJsonPath('errors.comprobante_ids.0', 'F001-1 ya tiene una baja en curso o aceptada.');
    }

    public function test_rechazo_no_anula_y_permite_otra_baja(): void
    {
        $f = $this->emitida();
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'Error de datos'])->assertCreated();
        $com = Comunicacion::withoutGlobalScopes()->sole();
        Http::fake([
            "{$this->base}/anulaciones" => Http::response(['estado' => 'exito', 'mensaje' => 'ok', 'datos' => ['id_anulacion' => 41, 'identifier' => 'RA-20261106-001', 'estado_sunat' => 'enviado']], 201),
            "{$this->base}/anulaciones/41/estado" => Http::response(['estado' => 'exito', 'datos' => ['id_anulacion' => 41, 'estado_sunat' => 'rechazado', 'codigo_sunat' => '2301',
                'descripcion_sunat' => 'La fecha del IssueDate no debe ser mayor a la fecha de recepción']]),
        ]);
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->enviarComunicacion(\App\Models\Comunicacion::withoutGlobalScopes()->find($com->id));
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->actualizarComunicacion(\App\Models\Comunicacion::withoutGlobalScopes()->find($com->id), forzar: true);
        $this->assertSame('rechazado', $com->fresh()->estado);
        $this->assertSame('aceptado', $f->fresh()->estado);
        $this->assertSame('8.0000', $this->stock());
        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$f->id], 'motivo' => 'Nuevo intento'])->assertCreated();
    }

    public function test_baja_de_nota_de_credito_revierte_sus_efectos(): void
    {
        $f = $this->emitida();
        $linea = Venta::withoutGlobalScopes()->find($f->venta_id)->detalles()->first();
        $this->actingAs($this->admin)->postJson('/api/v1/notas', ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '07', 'comprobante_id' => $f->id,
            'serie_id' => $this->fc01->id, 'cod_motivo' => '07', 'des_motivo' => 'Devolución de 1 mouse', 'items' => [['venta_detalle_id' => $linea->id, 'cantidad' => 1]]])->assertCreated();
        $nc = Comprobante::withoutGlobalScopes()->where('tipo_documento', '07')->sole();
        $nc->update(['estado' => 'aceptado', 'api_id' => 30]);
        $this->assertSame('9.0000', $this->stock());
        $this->assertSame('35.40', (string) Venta::withoutGlobalScopes()->find($f->venta_id)->saldo);

        $this->actingAs($this->admin)->postJson('/api/v1/bajas', ['comprobante_ids' => [$nc->id], 'motivo' => 'NC errónea'])->assertCreated();
        $com = Comunicacion::withoutGlobalScopes()->sole();
        $com->update(['estado' => 'aceptado', 'identifier' => 'RA-20261106-002', 'api_id' => 50]);
        app(BajaService::class)->aplicarEfectos($com);

        $this->assertSame('anulado', $nc->fresh()->estado);
        $this->assertSame('8.0000', $this->stock());                                                  // el mouse vuelve a salir
        $this->assertSame('70.80', (string) Venta::withoutGlobalScopes()->find($f->venta_id)->saldo); // se recupera la deuda
    }
}
