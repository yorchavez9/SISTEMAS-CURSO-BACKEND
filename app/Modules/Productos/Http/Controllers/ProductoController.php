<?php

namespace App\Modules\Productos\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Integrations\SunatApi\Exceptions\CredencialesNoConfiguradasException;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Models\Categoria;
use App\Models\Producto;
use App\Modules\Productos\Services\ImagenProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductoController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly Auditor $auditor, private readonly ImagenProductoService $imagenes) {}

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $p = Producto::query()->with(['categoria:id,nombre', 'presentaciones'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('codigo', 'like', "$q%")->orWhere('codigo_barras', $q)->orWhere('descripcion', 'like', "%$q%")))
            ->when($request->filled('categoria_id'), fn ($w) => $w->where('categoria_id', $request->query('categoria_id')))
            ->when($request->filled('tipo'), fn ($w) => $w->where('tipo', $request->query('tipo')))
            ->when($request->has('activo'), fn ($w) => $w->where('activo', $request->boolean('activo')))
            ->orderBy('descripcion')
            ->paginate(min(100, max(5, (int) $request->query('por_pagina', 15))));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($x) => $this->dto($x)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function show(Producto $producto): JsonResponse
    {
        return response()->json(['data' => $this->dto($producto->load(['categoria:id,nombre', 'presentaciones']))]);
    }

    public function store(Request $request): JsonResponse
    {
        [$data, $pres] = $this->validar($request);
        $producto = DB::transaction(function () use ($data, $pres) {
            $p = Producto::create($data);
            $p->presentaciones()->createMany($pres ?? []);

            return $p;
        });
        $this->imagenes->generar($producto->load('categoria')); // ilustración hasta que se suba una foto
        $this->auditor->registrar('producto.creado', $producto, [], $producto->only(['codigo', 'descripcion', 'precio_venta', 'tip_afe_igv']));

        return response()->json(['data' => $this->dto($producto->load(['categoria:id,nombre', 'presentaciones'])), 'message' => 'Producto registrado.'], 201);
    }

    public function update(Request $request, Producto $producto): JsonResponse
    {
        [$data, $pres] = $this->validar($request, $producto);
        $antes = $producto->only(array_keys($data));
        DB::transaction(function () use ($producto, $data, $pres) {
            $producto->update($data);
            if ($pres !== null) {
                $producto->presentaciones()->delete();
                $producto->presentaciones()->createMany($pres);
            }
        });
        $this->auditor->registrar('producto.actualizado', $producto, $antes, $producto->only(array_keys($data)));
        $this->imagenes->generar($producto->fresh('categoria')); // si es ilustración, se actualiza con el nuevo nombre/categoría

        return response()->json(['data' => $this->dto($producto->fresh(['categoria:id,nombre', 'presentaciones'])), 'message' => 'Producto actualizado.']);
    }

    /** Sube la foto del producto (JPG, PNG o WebP, hasta 4 MB); se reduce a 800 px y se guarda en WebP. */
    public function subirImagen(Request $request, Producto $producto): JsonResponse
    {
        $request->validate(['imagen' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [], ['imagen' => 'imagen']);
        $this->imagenes->subir($producto, $request->file('imagen'));
        $this->auditor->registrar('producto.imagen', $producto, [], ['codigo' => $producto->codigo, 'imagen' => 'foto']);

        return response()->json(['data' => $this->dto($producto->fresh(['categoria:id,nombre', 'presentaciones'])), 'message' => 'Imagen actualizada.']);
    }

    /** Quita la foto: el producto vuelve a la ilustración generada. */
    public function quitarImagen(Producto $producto): JsonResponse
    {
        $this->imagenes->quitar($producto->load('categoria'));
        $this->auditor->registrar('producto.imagen', $producto, [], ['codigo' => $producto->codigo, 'imagen' => 'ilustracion']);

        return response()->json(['data' => $this->dto($producto->fresh(['categoria:id,nombre', 'presentaciones'])), 'message' => 'Se quitó la foto.']);
    }

    public function destroy(Producto $producto): JsonResponse
    {
        $producto->delete();
        $this->auditor->registrar('producto.eliminado', $producto, $producto->only(['codigo', 'descripcion']));

        return response()->json(['message' => 'Producto eliminado.']);
    }

    /** Búsqueda en el catálogo de productos SUNAT a través de la API (GET /catalogos/producto-sunat?q=). */
    public function codigosSunat(Request $request, SunatApiClientFactory $factory): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['data' => []]);
        }
        try {
            $data = Cache::remember('prod_sunat:'.md5(mb_strtolower($q)), 86400, function () use ($factory, $q) {
                $r = $factory->paraEmpresa($this->empresa->get())->get('/catalogos/producto-sunat', ['q' => $q]);

                return $r->ok() && is_array($r->datos()) ? array_slice($r->datos(), 0, 30) : [];
            });
        } catch (CredencialesNoConfiguradasException) {
            abort(409, 'Configura las credenciales de la API para consultar el catálogo SUNAT.');
        } catch (SunatApiRateLimitException) {
            abort(429, 'La API está ocupada. Intenta en un minuto.');
        } catch (SunatApiConnectionException) {
            abort(503, 'No se pudo conectar con la API de facturación.');
        }

        return response()->json(['data' => $data]);
    }

    // ---- Categorías ----

    public function categorias(): JsonResponse
    {
        return response()->json(['data' => Categoria::query()->withCount('productos')->orderBy('nombre')->get(['id', 'nombre', 'descripcion', 'activo'])]);
    }

    public function storeCategoria(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('categorias')->where('empresa_id', $this->empresa->id())],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ], ['nombre.unique' => 'Ya existe una categoría con ese nombre.']);

        return response()->json(['data' => Categoria::create($data), 'message' => 'Categoría creada.'], 201);
    }

    public function destroyCategoria(Categoria $categoria): JsonResponse
    {
        if ($categoria->productos()->exists()) {
            throw ValidationException::withMessages(['categoria' => 'La categoría tiene productos. Reasígnalos antes de eliminarla.']);
        }
        $categoria->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }

    /** @return array{0: array, 1: ?array} datos del producto y presentaciones (null = no se enviaron) */
    private function validar(Request $request, ?Producto $p = null): array
    {
        $empresaId = $this->empresa->id();
        $req = $p ? 'sometimes' : 'required';
        $data = $request->validate([
            'codigo' => [$req, 'string', 'max:30', 'regex:/^\S+$/', Rule::unique('productos')->where('empresa_id', $empresaId)->ignore($p?->id)->whereNull('deleted_at')],
            'descripcion' => [$req, 'string', 'min:2', 'max:255'],
            'tipo' => [$req, Rule::in(['bien', 'servicio'])],
            'categoria_id' => ['nullable', Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)],
            'codigo_barras' => ['nullable', 'string', 'max:50'],
            'cod_producto_sunat' => ['nullable', 'regex:/^\d{8}$/'],
            'unidad' => [$req, Rule::exists('sunat_catalogos', 'codigo')->where('catalogo', '03')->where('activo', true)],
            'moneda' => ['sometimes', Rule::in(['PEN', 'USD'])],
            'precio_venta' => [$req, 'numeric', 'min:0', 'max:999999999'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            // Solo afectaciones activas (el 37 está deshabilitado por defecto de la API, H-11).
            'tip_afe_igv' => [$req, Rule::exists('tipos_afectacion_igv', 'codigo')->where('activo', true)],
            'porcentaje_isc' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'aplica_icbper' => ['sometimes', 'boolean'],
            'factor_icbper' => ['nullable', 'required_if:aplica_icbper,true', 'numeric', 'gt:0', 'max:10'],
            'controla_stock' => ['sometimes', 'boolean'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
            'presentaciones' => ['sometimes', 'array', 'max:20'],
            'presentaciones.*.nombre' => ['required', 'string', 'max:60'],
            'presentaciones.*.unidad' => ['required', Rule::exists('sunat_catalogos', 'codigo')->where('catalogo', '03')],
            'presentaciones.*.factor' => ['required', 'numeric', 'gt:0'],
            'presentaciones.*.precio_venta' => ['required', 'numeric', 'min:0'],
            'presentaciones.*.codigo_barras' => ['nullable', 'string', 'max:50'],
        ], [
            'tip_afe_igv.exists' => 'El tipo de afectación no existe o no está disponible.',
            'codigo.unique' => 'Ya existe un producto con ese código.',
            'cod_producto_sunat.regex' => 'El código de producto SUNAT tiene 8 dígitos.',
            'unidad.exists' => 'Unidad de medida no válida (catálogo 03).',
        ], ['tip_afe_igv' => 'afectación del IGV', 'precio_venta' => 'precio de venta']);

        if (isset($data['porcentaje_isc'])) {
            $data['tip_sis_isc'] = '01'; // único sistema validado con la API (F-14)
        } elseif (array_key_exists('porcentaje_isc', $data)) {
            $data['tip_sis_isc'] = null;
        }
        if (($data['tipo'] ?? $p?->tipo) === 'servicio') {
            $data['controla_stock'] = false;
        }
        if (array_key_exists('aplica_icbper', $data) && ! $data['aplica_icbper']) {
            $data['factor_icbper'] = null;
        }
        $pres = $data['presentaciones'] ?? null;
        unset($data['presentaciones']);

        return [$data, $pres];
    }

    private function dto(Producto $p): array
    {
        return [
            'id' => $p->id, 'codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'tipo' => $p->tipo,
            'categoria' => $p->categoria ? ['id' => $p->categoria->id, 'nombre' => $p->categoria->nombre] : null,
            'imagen_url' => $this->imagenes->url($p), 'imagen_generada' => $this->imagenes->esGenerada($p),
            'codigo_barras' => $p->codigo_barras, 'cod_producto_sunat' => $p->cod_producto_sunat, 'unidad' => $p->unidad, 'moneda' => $p->moneda,
            'precio_venta' => (float) $p->precio_venta, 'costo' => (float) $p->costo, 'tip_afe_igv' => $p->tip_afe_igv,
            'porcentaje_isc' => $p->porcentaje_isc !== null ? (float) $p->porcentaje_isc : null,
            'aplica_icbper' => $p->aplica_icbper, 'factor_icbper' => $p->factor_icbper !== null ? (float) $p->factor_icbper : null,
            'controla_stock' => $p->controla_stock, 'stock_minimo' => (float) $p->stock_minimo, 'activo' => $p->activo,
            'presentaciones' => $p->relationLoaded('presentaciones') ? $p->presentaciones->map(fn ($x) => [
                'id' => $x->id, 'nombre' => $x->nombre, 'unidad' => $x->unidad, 'factor' => (float) $x->factor,
                'precio_venta' => (float) $x->precio_venta, 'codigo_barras' => $x->codigo_barras,
            ]) : [],
        ];
    }
}
