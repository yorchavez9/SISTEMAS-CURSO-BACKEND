<?php

namespace Tests\Feature\Ventas;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\EmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VentasEmisionTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $vendedor;
    private Serie $f001;
    private Serie $b001;
    private Cliente $clienteRuc;
    private Producto $laptop;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = rtrim(config('services.sunat_api.base_url'), '/');
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->vendedor = User::factory()->create();
        $this->vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
        EmpresaApiCredencial::create(['empresa_id' => $this->empresa->id, 'entorno' => 'pruebas', 'api_key' => 'pk_test_123456',
            'api_secret' => 'sk_test_123456', 'verificacion_ok' => true, 'remoto' => ['tasa_igv_vigente' => 18]]);
        $est = Establecimiento::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Principal', 'cod_local' => '0000', 'api_sucursal_id' => 14]);
        $this->f001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '01', 'serie' => 'F001', 'correlativo_actual' => 962, 'api_serie_id' => 47]);
        $this->b001 = Serie::create(['empresa_id' => $this->empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => '03', 'serie' => 'B001', 'correlativo_actual' => 275, 'api_serie_id' => 48]);
        $this->clienteRuc = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '6', 'numero_documento' => '20131312955', 'razon_social' => 'SUNAT', 'direccion' => 'AV. GARCILASO 1472']);
        $this->laptop = Producto::create(['empresa_id' => $this->empresa->id, 'codigo' => 'PROD-001', 'descripcion' => 'Laptop Lenovo ThinkPad E14', 'unidad' => 'NIU', 'precio_venta' => 2360, 'tip_afe_igv' => '10', 'cod_producto_sunat' => '43211508']);
        // Stock inicial: la venta descuenta inventario en la misma transacción.
        $alm = app(\App\Modules\Inventario\Services\InventarioService::class)->almacenPrincipal($this->empresa->id, $est->id);
        app(\App\Modules\Inventario\Services\InventarioService::class)->registrar($this->empresa, $this->vendedor, $alm, 'entrada',
            [['producto_id' => $this->laptop->id, 'cantidad' => 50, 'costo_unitario' => 1500]], 'INV-INICIAL', null);
    }

    private function venta(array $o = []): array
    {
        return $o + ['idempotency_key' => (string) Str::uuid(), 'tipo_documento' => '01', 'serie_id' => $this->f001->id, 'cliente_id' => $this->clienteRuc->id,
            'items' => [['producto_id' => $this->laptop->id, 'cantidad' => 1]]];
    }

    // ------------------- Registro de venta -------------------

    public function test_registra_venta_y_encola_el_comprobante_con_el_payload_de_postman(): void
    {
        Queue::fake();
        $r = $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta())->assertCreated()
            ->assertJsonPath('data.comprobante.numero', 'F001-963')->assertJsonPath('data.comprobante.estado', 'por_enviar');

        $c = Comprobante::withoutGlobalScopes()->sole();
        $this->assertSame('2360.00', (string) $c->total);
        $this->assertSame('2000.00', (string) $c->gravadas);
        // Misma estructura que el ejemplo "Crear factura básica" (F-01) + correlativo explícito.
        // (la columna JSON de MySQL reordena claves y guarda 1.0 como 1: se compara sin orden ni tipo numérico)
        $this->assertEquals(['serie' => 'F001', 'correlativo' => 963, 'tipo_operacion' => '0101', 'tipo_moneda' => 'PEN', 'forma_pago' => 'Contado', 'enviar_automatico' => true],
            array_intersect_key($c->payload, array_flip(['serie', 'correlativo', 'tipo_operacion', 'tipo_moneda', 'forma_pago', 'enviar_automatico'])));
        $this->assertEquals(['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT', 'direccion' => 'AV. GARCILASO 1472'], $c->payload['cliente']);
        $this->assertEquals(['codigo' => 'PROD-001', 'cod_producto_sunat' => '43211508', 'descripcion' => 'Laptop Lenovo ThinkPad E14', 'unidad' => 'NIU', 'cantidad' => 1, 'precio_unitario' => 2360, 'tip_afe_igv' => '10'], $c->payload['items'][0]);
        $this->assertSame(['cliente', 'correlativo', 'enviar_automatico', 'fecha_emision', 'forma_pago', 'items', 'serie', 'tipo_moneda', 'tipo_operacion'], collect($c->payload)->keys()->sort()->values()->all());
        $this->assertSame(963, $this->f001->fresh()->correlativo_actual);
    }

    public function test_idempotencia_no_duplica_la_venta(): void
    {
        Queue::fake();
        $d = $this->venta();
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $d)->assertCreated();
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $d)->assertOk()->assertJsonPath('data.comprobante.numero', 'F001-963');
        $this->assertSame(1, Comprobante::withoutGlobalScopes()->count());
        $this->assertSame(963, $this->f001->fresh()->correlativo_actual);
    }

    public function test_correlativos_consecutivos(): void
    {
        Queue::fake();
        foreach ([964 - 1, 964, 965] as $_) {
            $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta())->assertCreated();
        }
        $this->assertSame([963, 964, 965], Comprobante::withoutGlobalScopes()->orderBy('id')->pluck('correlativo')->all());
    }

    public function test_reglas_de_negocio(): void
    {
        Queue::fake();
        $dni = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento' => '1', 'numero_documento' => '72243561', 'razon_social' => 'JORGE CHAVEZ']);
        // Factura a DNI → no
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['cliente_id' => $dni->id]))->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        // Boleta ≥ S/ 700 sin identificar → no; con DNI → sí
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['tipo_documento' => '03', 'serie_id' => $this->b001->id, 'cliente_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['tipo_documento' => '03', 'serie_id' => $this->b001->id, 'cliente_id' => $dni->id]))
            ->assertCreated()->assertJsonPath('data.comprobante.numero', 'B001-276');
        // Boleta pequeña sin cliente → consumidor final (ejemplo B-03)
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['tipo_documento' => '03', 'serie_id' => $this->b001->id, 'cliente_id' => null,
            'items' => [['descripcion' => 'Producto varios', 'cantidad' => 1, 'precio_unitario' => 12, 'tip_afe_igv' => '10']]]))->assertCreated();
        $this->assertEquals(['tipo_doc' => '0', 'num_doc' => '99999999', 'razon_social' => 'CONSUMIDOR FINAL'], Comprobante::withoutGlobalScopes()->latest('id')->first()->payload['cliente']);
        // Serie de boleta para factura → no
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['serie_id' => $this->b001->id]))->assertStatus(422)->assertJsonValidationErrors('serie_id');
        // Afectación 37 → no
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta(['items' => [['descripcion' => 'Muestra', 'cantidad' => 1, 'mto_valor_unitario' => 10, 'tip_afe_igv' => '37']]]))
            ->assertStatus(422)->assertJsonValidationErrors('items');
        // Ningún intento fallido consumió correlativo
        $this->assertSame(962, $this->f001->fresh()->correlativo_actual);
    }

    public function test_credito_con_cuotas_como_el_ejemplo_f03(): void
    {
        Queue::fake();
        $hoy = now('America/Lima');
        $bad = $this->venta(['forma_pago' => 'credito', 'cuotas' => [['monto' => 1000, 'fecha_vencimiento' => $hoy->copy()->addMonth()->toDateString()]]]);
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $bad)->assertStatus(422)->assertJsonValidationErrors('cuotas');

        $ok = $this->venta(['forma_pago' => 'credito', 'cuotas' => [
            ['monto' => 1180, 'fecha_vencimiento' => $hoy->copy()->addMonth()->toDateString()],
            ['monto' => 1180, 'fecha_vencimiento' => $hoy->copy()->addMonths(2)->toDateString()],
        ], 'items' => [['producto_id' => $this->laptop->id, 'cantidad' => 1]]]);
        $ok['items'] = [['producto_id' => $this->laptop->id, 'cantidad' => 1, 'precio_unitario' => 2360]];
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $ok)->assertCreated();
        $p = Comprobante::withoutGlobalScopes()->sole()->payload;
        $this->assertSame('Credito', $p['forma_pago']);
        $this->assertEquals([1180, 1180], array_column($p['cuotas'], 'monto'));
        $this->assertSame($hoy->copy()->addMonths(2)->toDateString(), $p['fecha_vencimiento']);
    }

    public function test_contador_no_puede_emitir(): void
    {
        $contador = User::factory()->create();
        $contador->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($contador)->postJson('/api/v1/ventas', $this->venta())->assertForbidden();
    }

    // ------------------- Emisión contra la API (respuestas reales de la Etapa 1) -------------------

    private function comprobantePorEnviar(): Comprobante
    {
        Queue::fake();
        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta())->assertCreated();

        return Comprobante::withoutGlobalScopes()->sole();
    }

    private function docApi(string $estado, array $o = []): array
    {
        return $o + ['id' => 500, 'tipo_documento' => '01', 'serie' => 'F001', 'correlativo' => 963, 'numero_completo' => 'F001-000963',
            'cliente' => ['tipo_doc' => '6', 'num_doc' => '20131312955', 'razon_social' => 'SUNAT'],
            'totales' => ['gravadas' => 2000, 'igv' => 360, 'total_impuestos' => 360, 'valor_venta' => 2000, 'sub_total' => 2360, 'total' => 2360],
            'sunat' => $estado === 'aceptado'
                ? ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'La Factura numero F001-963, ha sido aceptada', 'notas' => [], 'hash_cpe' => 'pRF7+dsqfgJ5H1gkhzjOrXCEHz4=']
                : ['estado' => $estado, 'codigo' => null, 'descripcion' => null, 'notas' => null, 'hash_cpe' => null]];
    }

    public function test_flujo_completo_enviado_aceptado_y_archivos(): void
    {
        Storage::fake('local');
        $c = $this->comprobantePorEnviar();
        Http::fake([
            "{$this->base}/facturas" => Http::response(['estado' => 'exito', 'mensaje' => 'Factura creada y encolada para envío a SUNAT.', 'datos' => $this->docApi('enviado')], 201),
            "{$this->base}/facturas/500" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => $this->docApi('aceptado')]),
            "{$this->base}/facturas/500/xml" => Http::response('<Invoice/>', 200, ['Content-Type' => 'application/xml']),
            "{$this->base}/facturas/500/cdr" => Http::response('PK-zip', 200, ['Content-Type' => 'application/zip']),
            "{$this->base}/facturas/500/pdf*" => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        ]);

        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->emitir(\App\Models\Comprobante::withoutGlobalScopes()->find($c->id));
        $c->refresh();
        $this->assertSame('enviado', $c->estado);
        $this->assertSame(500, $c->api_id);
        $this->assertFalse($c->diferencia_totales); // la API calculó lo mismo que el motor local
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['correlativo'] === 963 && $r['serie'] === 'F001');

        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->actualizar(\App\Models\Comprobante::withoutGlobalScopes()->find($c->id), forzar: true);
        $c->refresh();
        $this->assertSame('aceptado', $c->estado);
        $this->assertSame('0', $c->sunat_codigo);
        $this->assertNotNull($c->aceptado_at);

        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->descargarArchivos(\App\Models\Comprobante::withoutGlobalScopes()->find($c->id));
        $this->assertEqualsCanonicalizing(['xml', 'cdr', 'pdf'], $c->archivos()->pluck('tipo')->all());
        Storage::disk('local')->assertExists("comprobantes/{$this->empresa->id}/20161515648-01-F001-963.pdf");
        $this->assertSame(['por_enviar', 'enviado', 'aceptado'], $c->estados()->pluck('estado_nuevo')->all());

        // Descarga protegida
        $this->actingAs($this->vendedor)->get("/api/v1/comprobantes/{$c->id}/archivos/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->vendedor)->getJson("/api/v1/comprobantes/{$c->id}")->assertOk()
            ->assertJsonPath('data.estado', 'aceptado')->assertJsonCount(5, 'data.solicitudes');
    }

    public function test_respuesta_perdida_se_recupera_sin_duplicar(): void
    {
        $c = $this->comprobantePorEnviar();
        // La API ya tiene el documento (un envío anterior llegó) → 422 real de la Etapa 1, luego se busca por serie/correlativo.
        Http::fake([
            "{$this->base}/facturas" => Http::response(['estado' => 'error', 'mensaje' => 'Error de validación', 'errores' => ['correlativo' => ['El correlativo 963 ya existe para la serie F001. Use otro número o deje el campo vacío para autoincremento.']]], 422),
            "{$this->base}/facturas?*" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => ['datos' => [$this->docApi('aceptado')], 'paginacion' => ['total' => 1]]]),
        ]);
        $r = app(EmisionService::class)->emitir($c);
        $c->refresh();
        $this->assertSame(EmisionService::RESULTADO_FINAL, $r);
        $this->assertSame('aceptado', $c->estado);
        $this->assertSame(500, $c->api_id);
        Http::assertSentCount(2); // un POST y una consulta; nunca un segundo POST
    }

    public function test_numero_ocupado_por_otro_documento_se_renumera(): void
    {
        // Caso REAL (2026-11-06): otro sistema emitió F001-964 con la misma cuenta y serie.
        $c = $this->comprobantePorEnviar();
        $ajeno = $this->docApi('aceptado', ['cliente' => ['tipo_doc' => '6', 'num_doc' => '20493020618', 'razon_social' => 'TIENDAS PERUANAS SA'], 'totales' => ['total' => 12.7]]);
        Http::fake([
            "{$this->base}/facturas" => Http::response(['estado' => 'error', 'mensaje' => 'Error de validación', 'errores' => ['correlativo' => ['El correlativo 963 ya existe para la serie F001.']]], 422),
            "{$this->base}/facturas?*" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => ['datos' => [$ajeno]]]),
            "{$this->base}/series" => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [['id' => 47, 'tipo_documento' => '01', 'serie' => 'F001', 'correlativo' => 970, 'activo' => true]]]),
        ]);

        $this->assertSame(EmisionService::RESULTADO_RENUMERADO, app(EmisionService::class)->emitir($c));
        $c->refresh();
        $this->assertSame('por_enviar', $c->estado);
        $this->assertSame(971, $c->correlativo);              // siguiente al último usado en la API
        $this->assertSame(971, $c->payload['correlativo']);
        $this->assertSame(971, $this->f001->fresh()->correlativo_actual);
        $this->assertStringStartsWith('Renumerado de F001-963 a F001-971', $c->estados()->reorder('id', 'desc')->value('detalle'));

        // Tras 3 renumeraciones sin éxito se detiene y queda para revisión.
        app(EmisionService::class)->emitir($c->refresh());
        app(EmisionService::class)->emitir($c->refresh());
        $this->assertSame(EmisionService::RESULTADO_ERROR, app(EmisionService::class)->emitir($c->refresh()));
        $this->assertSame('error', $c->fresh()->estado);
        $this->assertStringStartsWith('Conflicto de numeración', $c->fresh()->ultimo_error);
    }

    public function test_error_de_validacion_de_la_api_no_se_reintenta_solo(): void
    {
        $c = $this->comprobantePorEnviar();
        Http::fake(["{$this->base}/facturas" => Http::response(['estado' => 'error', 'mensaje' => 'Error de validación',
            'errores' => ['cliente.tipo_doc' => ['Las facturas requieren cliente con RUC (tipo_doc=6).']]], 422)]);
        app(\App\Modules\Facturacion\Services\ProcesoSunat::class)->emitir(\App\Models\Comprobante::withoutGlobalScopes()->find($c->id));
        $c->refresh();
        $this->assertSame('error', $c->estado);
        $this->assertStringContainsString('requieren cliente con RUC', $c->ultimo_error);
    }

    public function test_falla_de_red_propaga_para_que_la_cola_reintente(): void
    {
        $c = $this->comprobantePorEnviar();
        Http::fake(["{$this->base}/facturas" => Http::response(['estado' => 'error', 'mensaje' => 'Server Error'], 503)]);
        $this->expectException(\App\Integrations\SunatApi\Exceptions\SunatApiConnectionException::class);
        try {
            app(EmisionService::class)->emitir($c);
        } finally {
            $this->assertSame('por_enviar', $c->fresh()->estado);
            $this->assertSame(1, $c->fresh()->intentos);
        }
    }

    public function test_comprobantes_aislados_por_empresa(): void
    {
        $c = $this->comprobantePorEnviar();
        $otra = Empresa::factory()->create();
        $intruso = User::factory()->create();
        $intruso->asignarEnEmpresa($otra, 'admin');
        $this->actingAs($intruso)->withHeader('X-Empresa-Id', (string) $otra->id)->getJson("/api/v1/comprobantes/{$c->id}")->assertNotFound();
        $this->actingAs($intruso)->withHeader('X-Empresa-Id', (string) $otra->id)->get("/api/v1/comprobantes/{$c->id}/archivos/pdf")->assertNotFound();
    }

    // ------------------- Sin cola de trabajos (la API envía a SUNAT por su cuenta) -------------------

    public function test_sin_cola_la_venta_se_envia_a_la_api_en_la_misma_solicitud(): void
    {
        config(['services.sunat_api.envio_inmediato' => true]);
        Http::fake(["{$this->base}/facturas" => Http::response(['estado' => 'exito', 'datos' => $this->docApi('aceptado')], 201)]);

        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta())->assertCreated()
            ->assertJsonPath('data.comprobante.estado', 'aceptado')
            ->assertJsonPath('message', 'Venta registrada. F001-963 aceptado por SUNAT.');
        $this->assertSame(500, (int) Comprobante::withoutGlobalScopes()->sole()->api_id);
        Http::assertSentCount(1);
    }

    public function test_sin_cola_si_la_api_no_responde_la_venta_se_registra_y_se_reintenta_al_consultar(): void
    {
        config(['services.sunat_api.envio_inmediato' => true]);
        Http::fake(["{$this->base}/facturas" => Http::sequence()
            ->pushFailedConnection()
            ->push(['estado' => 'exito', 'datos' => $this->docApi('enviado')], 201)]);

        $this->actingAs($this->vendedor)->postJson('/api/v1/ventas', $this->venta())->assertCreated()
            ->assertJsonPath('data.comprobante.estado', 'por_enviar');
        $c = Comprobante::withoutGlobalScopes()->sole();
        $this->assertNotNull($c->ultimo_error); // la venta no se pierde: queda pendiente con el motivo

        // Al abrir el comprobante se reenvía (la pantalla consulta cada pocos segundos mientras está pendiente).
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($this->vendedor)->getJson("/api/v1/comprobantes/{$c->id}")->assertOk()->assertJsonPath('data.estado', 'enviado');
        $this->assertSame(500, (int) $c->fresh()->api_id);
    }

    public function test_sin_cola_el_estado_y_los_archivos_se_obtienen_al_consultar(): void
    {
        Storage::fake('local');
        $c = $this->comprobantePorEnviar();
        $c->update(['estado' => 'enviado', 'api_id' => 500]);
        Http::fake([
            "{$this->base}/facturas/500" => Http::response(['estado' => 'exito', 'datos' => $this->docApi('aceptado')]),
            "{$this->base}/facturas/500/cdr" => Http::response('PK-zip', 200, ['Content-Type' => 'application/zip']),
        ]);

        $this->actingAs($this->vendedor)->getJson("/api/v1/comprobantes/{$c->id}")->assertOk()->assertJsonPath('data.estado', 'aceptado');
        // El CDR no estaba guardado: se trae de la API al descargarlo.
        $this->actingAs($this->vendedor)->get("/api/v1/comprobantes/{$c->id}/archivos/cdr")->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertSame(['cdr'], $c->archivos()->pluck('tipo')->all());
    }

    public function test_comando_sincronizar_consulta_pendientes_y_descarga_archivos(): void
    {
        Storage::fake('local');
        $c = $this->comprobantePorEnviar();
        $c->update(['estado' => 'enviado', 'api_id' => 500, 'updated_at' => now()->subMinute()]);
        Http::fake([
            "{$this->base}/facturas/500" => Http::response(['estado' => 'exito', 'datos' => $this->docApi('aceptado')]),
            "{$this->base}/facturas/500/xml" => Http::response('<Invoice/>', 200, ['Content-Type' => 'application/xml']),
            "{$this->base}/facturas/500/cdr" => Http::response('PK-zip', 200, ['Content-Type' => 'application/zip']),
            "{$this->base}/facturas/500/pdf*" => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->artisan('sunat:sincronizar')->assertSuccessful();
        $this->assertSame('aceptado', $c->fresh()->estado);
        $this->assertEqualsCanonicalizing(['xml', 'cdr', 'pdf'], $c->archivos()->pluck('tipo')->all());
    }
}
