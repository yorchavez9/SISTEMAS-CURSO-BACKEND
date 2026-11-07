<?php

namespace App\Modules\Ventas\Services;

use App\Core\Auditoria\Auditor;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\User;
use App\Models\Venta;
use App\Modules\Caja\Services\CajaService;
use App\Modules\Caja\Services\PagoService;
use App\Modules\Facturacion\Jobs\EmitirComprobanteJob;
use App\Modules\Inventario\Services\InventarioService;
use App\Modules\Facturacion\Payloads\ComprobantePayloadBuilder;
use App\Modules\Tributos\Calculo\CalculadoraComprobante;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Registra una venta y su comprobante en UNA transacción local (venta, detalles, cuotas, correlativo
 * reservado y comprobante "por_enviar"). El envío a la API ocurre DESPUÉS del commit, por cola.
 */
class VentaService
{
    /** Boletas desde este importe deben identificar al comprador (regla SUNAT). */
    public const BOLETA_MONTO_IDENTIFICACION = '700';

    public const CONSUMIDOR_FINAL = ['tipo_doc' => '0', 'num_doc' => '99999999', 'razon_social' => 'CONSUMIDOR FINAL']; // ejemplo B-03

    public function __construct(
        private readonly Auditor $auditor,
        private readonly CajaService $caja,
        private readonly PagoService $pagos,
        private readonly InventarioService $inventario,
    ) {}

    public function registrar(Empresa $empresa, User $user, array $d): Venta
    {
        // Idempotencia: el mismo formulario reenviado (doble clic, reintento de red) devuelve la venta ya creada.
        if ($existente = Venta::deEmpresa($empresa->id)->where('idempotency_key', $d['idempotency_key'])->first()) {
            return $existente->load('comprobante');
        }

        $tasa = $this->tasaIgv($empresa);
        $hoy = Carbon::now('America/Lima')->toDateString();
        $pagos = $d['pagos'] ?? [];
        $sesion = null;
        if ($pagos) {
            if (($d['forma_pago'] ?? 'contado') === 'credito') {
                throw ValidationException::withMessages(['pagos' => 'Las ventas al crédito se cobran por cuotas desde Cuentas por cobrar.']);
            }
            $sesion = $this->caja->sesionDe($empresa, $user)
                ?? throw ValidationException::withMessages(['caja' => 'Abre tu caja para registrar el cobro de la venta.']);
        }

        $venta = DB::transaction(function () use ($empresa, $user, $d, $tasa, $hoy, $pagos, $sesion) {
            $serie = Serie::deEmpresa($empresa->id)->lockForUpdate()->find($d['serie_id']);
            if (! $serie || ! $serie->activo || $serie->tipo_documento !== $d['tipo_documento'] || ! $serie->establecimiento_id) {
                throw ValidationException::withMessages(['serie_id' => 'La serie no es válida para este tipo de comprobante o está desactivada.']);
            }
            if (! $serie->api_serie_id) {
                throw ValidationException::withMessages(['serie_id' => 'La serie no está registrada en la API. Sincroniza establecimientos y series.']);
            }

            $cliente = ! empty($d['cliente_id']) ? Cliente::deEmpresa($empresa->id)->where('activo', true)->find($d['cliente_id']) : null;
            if (! empty($d['cliente_id']) && ! $cliente) {
                throw ValidationException::withMessages(['cliente_id' => 'Cliente no encontrado o inactivo.']);
            }

            [$items, $productos] = $this->items($empresa, $d['items']);
            $exportacion = collect($items)->every(fn ($i) => $i['tip_afe_igv'] === '40');
            if (! $exportacion && collect($items)->contains(fn ($i) => $i['tip_afe_igv'] === '40')) {
                throw ValidationException::withMessages(['items' => 'No se puede mezclar ítems de exportación (40) con ventas internas.']);
            }

            $doc = ['items' => $items];
            if (! empty($d['descuento_global'])) {
                $doc['descuentos_globales'] = [['cod_tipo' => '02', 'monto' => $d['descuento_global']]];
            }
            try {
                $calc = CalculadoraComprobante::conReglasDeBd()->calcular($doc, $tasa);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['items' => $e->getMessage()]);
            }
            $total = $calc['totales']['total'];

            $clienteApi = $this->clienteParaComprobante($d['tipo_documento'], $cliente, $exportacion, $total);
            [$formaPago, $cuotas, $vence] = $this->formaPago($d, $total, $hoy);

            if ($sesion && $sesion->caja->establecimiento_id !== $serie->establecimiento_id) {
                throw ValidationException::withMessages(['caja' => 'Tu caja abierta pertenece a otro establecimiento que la serie elegida.']);
            }

            $serie->correlativo_actual++;
            $serie->save();

            $montos = array_intersect_key($calc['totales'], array_flip(['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'base_ivap', 'igv', 'ivap', 'isc', 'icbper', 'descuentos_globales', 'anticipos', 'total_impuestos', 'valor_venta', 'sub_total', 'total']));

            // Venta desde una cotización: se bloquea y se marca convertida en la misma transacción.
            $cotizacion = null;
            if (! empty($d['cotizacion_id'])) {
                $cotizacion = Cotizacion::deEmpresa($empresa->id)->lockForUpdate()->find($d['cotizacion_id']);
                if (! $cotizacion || ! $cotizacion->convertible()) {
                    throw ValidationException::withMessages(['cotizacion_id' => 'La cotización no existe, ya se convirtió en venta, fue rechazada o venció.']);
                }
            }

            $venta = Venta::create($montos + [
                'empresa_id' => $empresa->id, 'establecimiento_id' => $serie->establecimiento_id, 'cliente_id' => $cliente?->id, 'cotizacion_id' => $cotizacion?->id,
                'user_id' => $user->id, 'fecha' => $hoy, 'moneda' => $d['moneda'] ?? 'PEN', 'forma_pago' => $formaPago,
                'tasa_igv' => $tasa, 'observacion' => $d['observacion'] ?? null, 'idempotency_key' => $d['idempotency_key'],
                'monto_pagado' => 0, 'saldo' => $total, 'estado_pago' => Dec::isZero($total) ? 'pagado' : 'pendiente',
            ]);
            foreach ($calc['items'] as $i => $it) {
                $venta->detalles()->create([
                    'producto_id' => $productos[$i]?->id, 'codigo' => $it['codigo'], 'descripcion' => $it['descripcion'], 'unidad' => $it['unidad'],
                    'cantidad' => $it['cantidad'], 'precio_unitario' => $it['precio_unitario'], 'tip_afe_igv' => $it['tip_afe_igv'],
                    'valor_unitario' => $it['valor_unitario'], 'valor_referencial' => $it['valor_referencial'], 'descuento' => $it['descuento'],
                    'base' => $it['base'], 'isc' => $it['isc'], 'igv' => $it['igv'], 'icbper' => $it['icbper'], 'total' => $it['total'],
                    'item_api' => $items[$i],
                ]);
            }
            foreach ($cuotas as $n => $c) {
                $venta->cuotas()->create(['numero' => $n + 1, 'monto' => $c['monto'], 'fecha_vencimiento' => $c['fecha_vencimiento']]);
            }

            $cab = [
                'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual, 'fecha_emision' => $hoy,
                'tipo_operacion' => $exportacion ? '0200' : '0101', 'moneda' => $venta->moneda, 'forma_pago' => $formaPago, 'fecha_vencimiento' => $vence,
            ];
            $payload = ComprobantePayloadBuilder::construir($cab, $clienteApi, $items, $cuotas, $doc['descuentos_globales'] ?? []);
            // Boletas en modo resumen: se registran en la API con solo_registro (ejemplo Postman B-06, aceptado)
            // y llegan a SUNAT en el resumen diario (RC de envío).
            // El modo se elige por venta; si no se indica, se usa el configurado en la empresa.
            $porResumen = $d['tipo_documento'] === '03' && ($d['envio_boleta'] ?? $empresa->envio_boletas ?? 'individual') === 'resumen';
            if ($porResumen) {
                unset($payload['enviar_automatico']);
                $payload['solo_registro'] = true;
            }
            $comprobante = Comprobante::create($montos + [
                'empresa_id' => $empresa->id, 'establecimiento_id' => $serie->establecimiento_id, 'venta_id' => $venta->id, 'serie_id' => $serie->id,
                'tipo_documento' => $d['tipo_documento'], 'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual,
                'fecha_emision' => $hoy, 'fecha_vencimiento' => $vence, 'tipo_operacion' => $cab['tipo_operacion'], 'moneda' => $venta->moneda,
                'forma_pago' => $formaPago, 'cliente_tipo_doc' => $clienteApi['tipo_doc'], 'cliente_num_doc' => $clienteApi['num_doc'],
                'cliente_razon_social' => $clienteApi['razon_social'], 'cliente_direccion' => $clienteApi['direccion'] ?? null,
                'estado' => 'por_enviar', 'envio_por_resumen' => $porResumen,
                'payload' => $payload,
            ]);
            $comprobante->estados()->create(['estado_anterior' => null, 'estado_nuevo' => 'por_enviar', 'detalle' => 'Venta registrada']);

            // Descuento de stock (bienes con control de stock) en la MISMA transacción: si falta stock no se registra nada.
            $this->inventario->descontarVenta($venta, $comprobante->numero(), $user->id);

            if ($pagos) {
                // Venta al contado: los pagos deben cubrir el total (el efectivo puede exceder → vuelto).
                $this->pagos->registrar($venta, $sesion, $user, $pagos, 'venta', exigirTotal: true);
            }

            $cotizacion?->update(['estado' => 'convertida', 'venta_id' => $venta->id]);

            // Se encola solo si la transacción confirma.
            EmitirComprobanteJob::dispatch($comprobante->id)->afterCommit();

            return $venta;
        });

        $this->auditor->registrar('venta.registrada', $venta, [], ['comprobante' => $venta->comprobante->numero(), 'total' => (string) $venta->total], $empresa->id);

        return $venta->load('comprobante');
    }

    public function tasaIgv(Empresa $empresa): string
    {
        $t = $empresa->credencialApi()?->remoto['tasa_igv_vigente'] ?? null;

        return is_numeric($t) ? (string) $t : '18';
    }

    /** @return array{0: list<array>, 1: list<?Producto>} ítems con la forma de la API y productos de origen */
    private function items(Empresa $empresa, array $entrada): array
    {
        $productos = Producto::deEmpresa($empresa->id)->whereIn('id', collect($entrada)->pluck('producto_id')->filter())->get()->keyBy('id');
        $items = [];
        $origen = [];
        foreach ($entrada as $i => $e) {
            $p = null;
            if (! empty($e['producto_id'])) {
                $p = $productos->get($e['producto_id']);
                if (! $p || ! $p->activo) {
                    throw ValidationException::withMessages(["items.$i.producto_id" => 'Producto no encontrado o inactivo.']);
                }
                $item = $p->comoItem((string) $e['cantidad'], isset($e['precio_unitario']) ? (string) $e['precio_unitario'] : null, $e['tip_afe_igv'] ?? null);
                if (! empty($e['descripcion'])) {
                    $item['descripcion'] = $e['descripcion'];
                }
            } else {
                $item = ['codigo' => $e['codigo'] ?? null, 'descripcion' => $e['descripcion'], 'unidad' => $e['unidad'] ?? 'NIU',
                    'cantidad' => (string) $e['cantidad'], 'precio_unitario' => (string) ($e['precio_unitario'] ?? 0), 'tip_afe_igv' => $e['tip_afe_igv'] ?? '10'];
            }
            if (isset($e['mto_valor_unitario'])) {
                // Gratuitas: precio 0 + valor referencial (ejemplos F-07, F-17)
                $item['precio_unitario'] = 0;
                $item['mto_valor_unitario'] = (float) $e['mto_valor_unitario'];
            }
            if (! empty($e['descuento']) && Dec::cmp($e['descuento'], '0') > 0) {
                $item['descuentos'] = [['cod_tipo' => '00', 'monto' => (float) $e['descuento']]]; // F-11
            }
            $item['cantidad'] = (float) $item['cantidad'];
            $item['precio_unitario'] = (float) $item['precio_unitario'];
            $items[] = array_filter($item, fn ($v) => $v !== null);
            $origen[] = $p;
        }

        return [$items, $origen];
    }

    private function clienteParaComprobante(string $tipo, ?Cliente $c, bool $exportacion, string $total): array
    {
        if ($tipo === '01') {
            if (! $c) {
                throw ValidationException::withMessages(['cliente_id' => 'La factura requiere un cliente.']);
            }
            if (! $exportacion && $c->tipo_documento !== '6') {
                throw ValidationException::withMessages(['cliente_id' => 'La factura requiere un cliente con RUC. Para DNI u otros documentos emite una boleta.']);
            }

            return $c->paraComprobante();
        }
        if (! $c || $c->tipo_documento === '0') {
            if (Dec::cmp($total, self::BOLETA_MONTO_IDENTIFICACION) >= 0) {
                throw ValidationException::withMessages(['cliente_id' => 'Las boletas desde S/ 700 deben identificar al comprador (DNI, CE, RUC…).']);
            }

            return $c ? $c->paraComprobante() : self::CONSUMIDOR_FINAL;
        }

        return $c->paraComprobante();
    }

    /** @return array{0: string, 1: list<array>, 2: ?string} */
    private function formaPago(array $d, string $total, string $hoy): array
    {
        if (($d['forma_pago'] ?? 'contado') !== 'credito') {
            return ['contado', [], null];
        }
        if ($d['tipo_documento'] !== '01') {
            throw ValidationException::withMessages(['forma_pago' => 'La venta al crédito con cuotas está validada solo para facturas (ejemplo F-03).']);
        }
        $cuotas = array_values($d['cuotas'] ?? []);
        if (! $cuotas) {
            throw ValidationException::withMessages(['cuotas' => 'Indica al menos una cuota.']);
        }
        $suma = '0';
        $previa = $hoy;
        foreach ($cuotas as $i => $c) {
            if ($c['fecha_vencimiento'] <= $previa) {
                throw ValidationException::withMessages(["cuotas.$i.fecha_vencimiento" => 'Cada cuota debe vencer después de la emisión y de la cuota anterior.']);
            }
            $previa = $c['fecha_vencimiento'];
            $suma = Dec::add($suma, Dec::round($c['monto']));
        }
        if (Dec::cmp(Dec::round($suma), $total) !== 0) {
            throw ValidationException::withMessages(['cuotas' => "La suma de cuotas ({$suma}) debe ser igual al total ({$total})."]);
        }

        return ['credito', $cuotas, end($cuotas)['fecha_vencimiento']];
    }
}
