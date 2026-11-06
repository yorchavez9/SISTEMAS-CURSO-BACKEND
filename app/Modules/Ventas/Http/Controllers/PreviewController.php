<?php

namespace App\Modules\Ventas\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Modules\Tributos\Calculo\CalculadoraComprobante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Vista previa de una venta: el frontend NO calcula impuestos; envía ítems y recibe los totales
 * del motor validado con los 47 comprobantes aceptados por SUNAT (Etapa 1).
 */
class PreviewController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['nullable', 'integer'],
            'items.*.descripcion' => ['required_without:items.*.producto_id', 'nullable', 'string', 'max:255'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.mto_valor_unitario' => ['nullable', 'numeric', 'gt:0'],
            'items.*.tip_afe_igv' => ['nullable', 'string', 'size:2'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'descuento_global' => ['nullable', 'numeric', 'min:0'],
            'descuento_global_tipo' => ['nullable', 'in:02,03'],
            'anticipos' => ['nullable', 'array'],
            'anticipos.*.monto' => ['required', 'numeric', 'gt:0'],
        ]);

        $productos = Producto::query()->whereIn('id', collect($data['items'])->pluck('producto_id')->filter())->get()->keyBy('id');
        $items = [];
        foreach ($data['items'] as $i => $it) {
            if (! empty($it['producto_id'])) {
                $p = $productos->get($it['producto_id']) ?? throw ValidationException::withMessages(["items.$i.producto_id" => 'Producto no encontrado.']);
                $item = $p->comoItem($it['cantidad'], isset($it['precio_unitario']) ? (string) $it['precio_unitario'] : null, $it['tip_afe_igv'] ?? null);
            } else {
                $item = ['descripcion' => $it['descripcion'], 'unidad' => 'NIU', 'cantidad' => $it['cantidad'],
                    'precio_unitario' => $it['precio_unitario'] ?? 0, 'tip_afe_igv' => $it['tip_afe_igv'] ?? '10'];
            }
            if (isset($it['mto_valor_unitario'])) {
                $item['mto_valor_unitario'] = $it['mto_valor_unitario'];
            }
            if (! empty($it['descuento'])) {
                $item['descuentos'] = [['cod_tipo' => '00', 'monto' => $it['descuento']]];
            }
            $items[] = $item;
        }

        $doc = ['items' => $items, 'anticipos' => $data['anticipos'] ?? []];
        if (! empty($data['descuento_global'])) {
            $doc['descuentos_globales'] = [['cod_tipo' => $data['descuento_global_tipo'] ?? '02', 'monto' => $data['descuento_global']]];
        }

        $tasa = $this->tasaIgv();
        try {
            $r = CalculadoraComprobante::conReglasDeBd()->calcular($doc, $tasa);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return response()->json(['data' => $r + ['tasa_igv' => (float) $tasa]]);
    }

    /** Tasa vigente informada por la API para la empresa (régimen / override); 18 % si aún no se verificó. */
    private function tasaIgv(): string
    {
        $e = $this->empresa->get();
        $t = $e->credencialApi()?->remoto['tasa_igv_vigente'] ?? null;

        return is_numeric($t) ? (string) $t : '18';
    }
}
