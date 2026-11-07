<?php

namespace App\Modules\Facturacion\Services;

use App\Core\Auditoria\Auditor;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Empresa;
use App\Models\Serie;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Modules\Caja\Services\CajaService;
use App\Modules\Caja\Services\PagoService;
use App\Modules\Facturacion\Jobs\EmitirComprobanteJob;
use App\Modules\Inventario\Services\InventarioService;
use App\Modules\Tributos\Calculo\CalculadoraComprobante;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Notas de crédito (07) y débito (08) sobre una factura o boleta aceptada.
 * Payload según los ejemplos Postman NC-01…NC-06 y ND-01…ND-05 (todos aceptados en la Etapa 1).
 */
class NotaService
{
    /** Catálogo 09: motivos que anulan/devuelven toda la operación. */
    public const NC_TOTAL = ['01', '02', '06'];
    public const NC_POR_ITEM = ['07'];
    /** Motivos por monto (descuentos, bonificación, disminución de valor, otros, corrección). */
    public const NC_POR_MONTO = ['03', '04', '05', '08', '09', '10'];
    public const ND_MOTIVOS = ['01', '02', '03'];
    /** Motivos que implican reingreso de mercadería. */
    public const NC_DEVUELVE_STOCK = ['01', '02', '06', '07'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly InventarioService $inventario,
        private readonly PagoService $pagos,
        private readonly CajaService $caja,
    ) {}

    public function emitir(Empresa $empresa, User $user, array $d): Comprobante
    {
        if ($ya = Comprobante::deEmpresa($empresa->id)->where('idempotency_key', $d['idempotency_key'])->first()) {
            return $ya;
        }
        $esNC = $d['tipo_documento'] === '07';
        $motivo = $d['cod_motivo'];
        $validos = $esNC ? [...self::NC_TOTAL, ...self::NC_POR_ITEM, ...self::NC_POR_MONTO] : self::ND_MOTIVOS;
        if (! in_array($motivo, $validos, true)) {
            throw ValidationException::withMessages(['cod_motivo' => 'Motivo no admitido para este tipo de nota.']);
        }
        $sesion = null;
        if ($esNC && ! empty($d['reembolso']['metodo'])) {
            $sesion = $this->caja->sesionDe($empresa, $user) ?? throw ValidationException::withMessages(['reembolso' => 'Abre tu caja para registrar el reembolso.']);
        }

        $nota = DB::transaction(function () use ($empresa, $user, $d, $esNC, $motivo, $sesion) {
            $ref = Comprobante::deEmpresa($empresa->id)->with('venta')->lockForUpdate()->find($d['comprobante_id']);
            if (! $ref || ! in_array($ref->tipo_documento, ['01', '03'], true) || ! $ref->venta) {
                throw ValidationException::withMessages(['comprobante_id' => 'Solo se emiten notas sobre facturas o boletas registradas en el sistema.']);
            }
            if ($ref->anulado_por_nota_id) {
                throw ValidationException::withMessages(['comprobante_id' => "El comprobante {$ref->numero()} ya fue anulado por la nota de crédito {$ref->anuladoPorNota?->numero()}."]);
            }
            if ($ref->estado !== 'aceptado') {
                throw ValidationException::withMessages(['comprobante_id' => "El comprobante {$ref->numero()} no está aceptado por SUNAT (estado: {$ref->estado})."]);
            }
            $venta = Venta::withoutGlobalScopes()->lockForUpdate()->findOrFail($ref->venta_id);

            $serie = Serie::deEmpresa($empresa->id)->lockForUpdate()->find($d['serie_id']);
            if (! $serie || ! $serie->activo || $serie->tipo_documento !== $d['tipo_documento'] || ! $serie->api_serie_id) {
                throw ValidationException::withMessages(['serie_id' => 'Serie no válida para este tipo de nota o no sincronizada con la API.']);
            }
            if ($serie->serie[0] !== $ref->serie[0]) {
                throw ValidationException::withMessages(['serie_id' => "La nota de un comprobante {$ref->serie} debe usar una serie que empiece con {$ref->serie[0]} (p. ej. {$ref->serie[0]}C01)."]);
            }

            $previas = Comprobante::deEmpresa($empresa->id)->where('comprobante_referencia_id', $ref->id)->where('tipo_documento', '07')->where('estado', '!=', 'error')->get();
            [$items, $origen] = $this->items($d, $ref, $venta, $esNC, $motivo, $previas);
            try {
                $calc = CalculadoraComprobante::conReglasDeBd()->calcular(['items' => $items], (string) $venta->tasa_igv);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['items' => $e->getMessage()]);
            }
            $total = $calc['totales']['total'];
            if ($esNC) {
                $acumulado = Dec::add($previas->reduce(fn ($s, $n) => Dec::add($s, $n->total), '0'), $total);
                if (Dec::cmp($acumulado, $ref->total) > 0) {
                    $disp = Dec::round(Dec::sub($ref->total, Dec::sub($acumulado, $total)));
                    throw ValidationException::withMessages(['items' => "Las notas de crédito no pueden superar el total de {$ref->numero()}: disponible {$disp} (SUNAT 3286)."]);
                }
            }

            $serie->correlativo_actual++;
            $serie->save();
            $hoy = Carbon::now('America/Lima')->toDateString();
            // Sin array_filter "a secas": descartaría tipo_doc "0" (consumidor final) y la API respondería 422.
            $cliente = array_filter(['tipo_doc' => $ref->cliente_tipo_doc, 'num_doc' => $ref->cliente_num_doc, 'razon_social' => $ref->cliente_razon_social, 'direccion' => $ref->cliente_direccion],
                fn ($v) => $v !== null && $v !== '');
            // Estructura de los ejemplos Postman NC-01 / ND-01 (doc_afectado_*, cod_motivo, des_motivo).
            $payload = [
                'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual, 'fecha_emision' => $hoy, 'tipo_moneda' => $ref->moneda,
                'doc_afectado_tipo' => $ref->tipo_documento, 'doc_afectado_serie' => $ref->serie, 'doc_afectado_correlativo' => (string) $ref->correlativo,
                'cod_motivo' => $motivo, 'des_motivo' => $d['des_motivo'], 'cliente' => $cliente, 'items' => $items, 'enviar_automatico' => true,
            ];
            $montos = array_intersect_key($calc['totales'], array_flip(['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'base_ivap', 'igv', 'ivap', 'isc', 'icbper', 'total_impuestos', 'valor_venta', 'sub_total', 'total']));
            $nota = Comprobante::create($montos + [
                'empresa_id' => $empresa->id, 'establecimiento_id' => $serie->establecimiento_id, 'comprobante_referencia_id' => $ref->id,
                'serie_id' => $serie->id, 'tipo_documento' => $d['tipo_documento'], 'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual,
                'fecha_emision' => $hoy, 'tipo_operacion' => $ref->tipo_operacion, 'moneda' => $ref->moneda, 'forma_pago' => 'contado',
                'cliente_tipo_doc' => $ref->cliente_tipo_doc, 'cliente_num_doc' => $ref->cliente_num_doc, 'cliente_razon_social' => $ref->cliente_razon_social,
                'cliente_direccion' => $ref->cliente_direccion, 'cod_motivo' => $motivo, 'des_motivo' => $d['des_motivo'], 'idempotency_key' => $d['idempotency_key'],
                'estado' => 'por_enviar', 'payload' => $payload,
            ]);
            $devolver = $esNC && in_array($motivo, self::NC_DEVUELVE_STOCK, true) && ($d['devolver_stock'] ?? true);
            foreach ($calc['items'] as $i => $it) {
                ComprobanteItem::create([
                    'comprobante_id' => $nota->id, 'venta_detalle_id' => $origen[$i]?->id, 'producto_id' => $origen[$i]?->producto_id,
                    'descripcion' => $it['descripcion'], 'unidad' => $it['unidad'], 'cantidad' => $it['cantidad'], 'precio_unitario' => $it['precio_unitario'],
                    'tip_afe_igv' => $it['tip_afe_igv'], 'base' => $it['base'], 'isc' => $it['isc'], 'igv' => $it['igv'], 'icbper' => $it['icbper'], 'total' => $it['total'],
                    'devuelve_stock' => $devolver && $origen[$i]?->producto_id !== null,
                ]);
            }
            $nota->estados()->create(['estado_anterior' => null, 'estado_nuevo' => 'por_enviar', 'detalle' => ($esNC ? 'Nota de crédito' : 'Nota de débito')." sobre {$ref->numero()}: {$d['des_motivo']}"]);

            // Efectos comerciales
            if ($esNC) {
                $venta->total_notas_credito = Dec::round(Dec::add($venta->total_notas_credito, $total));
                if (in_array($motivo, ['01', '02'], true)) {
                    $venta->estado = 'anulada';
                }
            } else {
                $venta->total_notas_debito = Dec::round(Dec::add($venta->total_notas_debito, $total));
            }
            $venta->save();
            if ($devolver) {
                $lineas = collect($calc['items'])->keys()->filter(fn ($i) => $origen[$i]?->producto_id)
                    ->map(fn ($i) => ['producto_id' => $origen[$i]->producto_id, 'cantidad' => $calc['items'][$i]['cantidad']])->values()->all();
                $this->inventario->devolverPorNota($venta, $lineas, $nota->numero(), $user->id);
            }
            $this->pagos->recalcular($venta);
            if ($sesion) {
                // Se reembolsa lo pagado en exceso respecto del nuevo neto (como máximo el total de la nota).
                $exceso = Dec::sub('0', $venta->fresh()->saldo);
                if (Dec::cmp($exceso, '0') > 0) {
                    $this->pagos->reembolsar($venta, $sesion, $user, Dec::round(Dec::cmp($exceso, $total) > 0 ? $total : $exceso), $d['reembolso']['metodo'], $nota->id);
                    $this->pagos->recalcular($venta);
                }
            }

            EmitirComprobanteJob::dispatch($nota->id)->afterCommit();

            return $nota;
        });

        $this->auditor->registrar($esNC ? 'nota_credito.emitida' : 'nota_debito.emitida', $nota, [], [
            'numero' => $nota->numero(), 'referencia' => $nota->referencia?->numero(), 'motivo' => $nota->cod_motivo, 'total' => (string) $nota->total,
        ], $empresa->id);

        return $nota;
    }

    /** @return array{0: list<array>, 1: list<?VentaDetalle>} ítems con la forma de la API y la línea original de cada uno */
    private function items(array $d, Comprobante $ref, Venta $venta, bool $esNC, string $motivo, $previas): array
    {
        $detalles = $venta->detalles()->get()->keyBy('id');
        $vendibles = $detalles->filter(fn ($l) => $l->valor_referencial === null); // las líneas gratuitas no generan crédito
        $tipAfeDefecto = $vendibles->first()?->tip_afe_igv ?? '10';

        if ($esNC && in_array($motivo, self::NC_TOTAL, true)) {
            if ($previas->isNotEmpty()) {
                throw ValidationException::withMessages(['cod_motivo' => 'Ya existen notas de crédito sobre este comprobante: usa devolución por ítem o un motivo por monto.']);
            }
            $lineas = $detalles->values();
            $items = $lineas->map(fn (VentaDetalle $l) => $l->item_api)->all();

            return [$items, $lineas->all()];
        }

        if ($esNC && in_array($motivo, self::NC_POR_ITEM, true)) {
            $devueltas = ComprobanteItem::query()->whereIn('comprobante_id', $previas->pluck('id'))->whereNotNull('venta_detalle_id')
                ->selectRaw('venta_detalle_id, SUM(cantidad) as cant')->groupBy('venta_detalle_id')->pluck('cant', 'venta_detalle_id');
            $items = [];
            $origen = [];
            foreach ($d['items'] ?? [] as $i => $e) {
                $l = $vendibles->get($e['venta_detalle_id'] ?? 0) ?? throw ValidationException::withMessages(["items.$i.venta_detalle_id" => 'Línea no encontrada en el comprobante (o es gratuita).']);
                $disponible = Dec::sub($l->cantidad, $devueltas[$l->id] ?? '0');
                if (Dec::cmp($e['cantidad'], $disponible) > 0) {
                    throw ValidationException::withMessages(["items.$i.cantidad" => "Solo se pueden devolver ".rtrim(rtrim(Dec::round($disponible, 4), '0'), '.')." de {$l->descripcion}."]);
                }
                // Precio efectivo por unidad (incluye descuentos de la línea original).
                $item = $l->item_api;
                unset($item['descuentos']);
                $item['cantidad'] = (float) $e['cantidad'];
                $item['precio_unitario'] = (float) Dec::round(Dec::div($l->total, $l->cantidad), 6);
                $items[] = $item;
                $origen[] = $l;
            }
            if (! $items) {
                throw ValidationException::withMessages(['items' => 'Indica las líneas y cantidades devueltas.']);
            }

            return [$items, $origen];
        }

        // Por monto (NC 03/04/05/08/09/10 y ND 01/02/03): ítems libres, como NC-02 y ND-01 de Postman.
        $items = [];
        foreach ($d['items'] ?? [] as $i => $e) {
            if (empty($e['descripcion']) || ! isset($e['precio_unitario']) || Dec::cmp($e['precio_unitario'], '0') <= 0) {
                throw ValidationException::withMessages(["items.$i" => 'Indica descripción e importe mayor a cero.']);
            }
            $items[] = ['descripcion' => $e['descripcion'], 'unidad' => $e['unidad'] ?? 'ZZ', 'cantidad' => (float) ($e['cantidad'] ?? 1),
                'precio_unitario' => (float) $e['precio_unitario'], 'tip_afe_igv' => $e['tip_afe_igv'] ?? $tipAfeDefecto];
        }
        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Indica al menos un concepto con su importe.']);
        }

        return [$items, array_fill(0, count($items), null)];
    }
}
