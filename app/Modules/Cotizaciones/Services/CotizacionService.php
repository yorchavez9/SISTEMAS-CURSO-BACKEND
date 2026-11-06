<?php

namespace App\Modules\Cotizaciones\Services;

use App\Core\Auditoria\Auditor;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use App\Modules\Tributos\Calculo\CalculadoraComprobante;
use App\Modules\Ventas\Services\VentaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CotizacionService
{
    public function __construct(private readonly Auditor $auditor, private readonly VentaService $ventas) {}

    public function crear(Empresa $empresa, User $user, array $d): Cotizacion
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        if ($d['fecha_validez'] < $hoy) {
            throw ValidationException::withMessages(['fecha_validez' => 'La validez no puede ser anterior a hoy.']);
        }
        $cliente = ! empty($d['cliente_id']) ? Cliente::deEmpresa($empresa->id)->where('activo', true)->find($d['cliente_id']) : null;
        if (! empty($d['cliente_id']) && ! $cliente) {
            throw ValidationException::withMessages(['cliente_id' => 'Cliente no encontrado o inactivo.']);
        }
        if (! $cliente && empty($d['cliente_nombre'])) {
            throw ValidationException::withMessages(['cliente_id' => 'Elige un cliente o escribe el nombre del prospecto.']);
        }

        // Mismo motor de cálculo que la venta: lo cotizado coincide con lo que se facturará.
        $productos = Producto::deEmpresa($empresa->id)->whereIn('id', collect($d['items'])->pluck('producto_id')->filter())->get()->keyBy('id');
        $items = [];
        $origen = [];
        foreach ($d['items'] as $i => $it) {
            $p = null;
            if (! empty($it['producto_id'])) {
                $p = $productos->get($it['producto_id']);
                if (! $p || ! $p->activo) {
                    throw ValidationException::withMessages(["items.$i.producto_id" => 'Producto no encontrado o inactivo.']);
                }
                $item = $p->comoItem((string) $it['cantidad'], isset($it['precio_unitario']) ? (string) $it['precio_unitario'] : null);
                if (! empty($it['descripcion'])) {
                    $item['descripcion'] = $it['descripcion'];
                }
            } else {
                $item = ['descripcion' => $it['descripcion'], 'unidad' => $it['unidad'] ?? 'NIU', 'cantidad' => (string) $it['cantidad'],
                    'precio_unitario' => (string) ($it['precio_unitario'] ?? 0), 'tip_afe_igv' => $it['tip_afe_igv'] ?? '10'];
            }
            if (! empty($it['descuento']) && (float) $it['descuento'] > 0) {
                $item['descuentos'] = [['cod_tipo' => '00', 'monto' => (float) $it['descuento']]];
            }
            $items[] = $item;
            $origen[] = $p;
        }
        $tasa = $this->ventas->tasaIgv($empresa);
        try {
            $calc = CalculadoraComprobante::conReglasDeBd()->calcular(['items' => $items], $tasa);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
        if ((float) $calc['totales']['gratuitas'] > 0) {
            throw ValidationException::withMessages(['items' => 'Las cotizaciones no admiten operaciones gratuitas.']);
        }

        $cot = DB::transaction(function () use ($empresa, $user, $d, $hoy, $cliente, $origen, $calc, $tasa) {
            // Correlativo por empresa con bloqueo: dos cotizaciones simultáneas no comparten número.
            $numero = (int) Cotizacion::deEmpresa($empresa->id)->lockForUpdate()->max('numero') + 1;
            $t = $calc['totales'];
            $cot = Cotizacion::create([
                'empresa_id' => $empresa->id, 'numero' => $numero, 'cliente_id' => $cliente?->id,
                'cliente_tipo_doc' => $cliente?->tipo_documento, 'cliente_num_doc' => $cliente?->numero_documento,
                'cliente_razon_social' => $cliente?->razon_social ?? $d['cliente_nombre'], 'cliente_email' => $cliente?->email ?? ($d['cliente_email'] ?? null),
                'fecha_emision' => $hoy, 'fecha_validez' => $d['fecha_validez'], 'moneda' => $d['moneda'] ?? 'PEN', 'tasa_igv' => $tasa,
                'gravadas' => $t['gravadas'], 'exoneradas' => $t['exoneradas'], 'inafectas' => $t['inafectas'], 'exportacion' => $t['exportacion'],
                'igv' => $t['igv'], 'isc' => $t['isc'], 'icbper' => $t['icbper'],
                'descuentos' => collect($calc['items'])->sum(fn ($i) => (float) $i['descuento']), 'total' => $t['total'],
                'observacion' => $d['observacion'] ?? null, 'estado' => 'borrador', 'user_id' => $user->id,
            ]);
            foreach ($calc['items'] as $i => $c) {
                $cot->items()->create([
                    'producto_id' => $origen[$i]?->id, 'codigo' => $c['codigo'], 'descripcion' => $c['descripcion'], 'unidad' => $c['unidad'],
                    'cantidad' => $c['cantidad'], 'precio_unitario' => $c['precio_unitario'], 'tip_afe_igv' => $c['tip_afe_igv'],
                    'descuento' => $c['descuento'], 'base' => $c['base'], 'igv' => $c['igv'], 'total' => $c['total'],
                ]);
            }

            return $cot;
        });
        $this->auditor->registrar('cotizacion.creada', $cot, [], ['numero' => $cot->codigo(), 'total' => (string) $cot->total], $empresa->id);

        return $cot;
    }

    public function cambiarEstado(Cotizacion $c, string $nuevo): Cotizacion
    {
        if ($c->vencida() && $nuevo !== 'rechazada') {
            throw ValidationException::withMessages(['estado' => "La cotización venció el {$c->fecha_validez->format('d/m/Y')}. Emite una nueva."]);
        }
        if (! in_array($nuevo, Cotizacion::TRANSICIONES[$c->estado] ?? [], true)) {
            throw ValidationException::withMessages(['estado' => "No se puede pasar de {$c->estado} a $nuevo."]);
        }
        $antes = $c->estado;
        $c->update(['estado' => $nuevo]);
        $this->auditor->registrar('cotizacion.estado', $c, ['estado' => $antes], ['estado' => $nuevo], $c->empresa_id);

        return $c;
    }
}
