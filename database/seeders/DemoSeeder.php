<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Stock;
use App\Models\User;
use App\Modules\Inventario\Services\InventarioService;
use App\Modules\Productos\Services\ImagenProductoService;
use Illuminate\Database\Seeder;

/**
 * Datos de DEMOSTRACIÓN para una empresa ya registrada: categorías, productos (gravados, exonerados,
 * con ICBPER y servicios), presentaciones, clientes, imagen de cada producto y stock inicial en cada almacén para TODOS los bienes
 * de la empresa (también los que creó el usuario) que aún no tengan existencias.
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *   DEMO_EMPRESA_RUC=20123456789 php artisan db:seed --class=DemoSeeder --force   (si hay varias empresas)
 *
 * Idempotente: se puede ejecutar varias veces; no duplica ni modifica lo que ya existe
 * (busca por nombre de categoría, código de producto y documento del cliente). No emite comprobantes
 * ni llama a la API de facturación. NO usar en una empresa real con datos propios.
 */
class DemoSeeder extends Seeder
{
    private const CATEGORIAS = [
        'TECNOLOGIA' => 'Equipos de cómputo',
        'ACCESORIOS DE COMPUTO' => 'Periféricos y accesorios para computadoras',
        'OFICINA Y PAPELERIA' => 'Útiles de oficina y papelería',
        'ABARROTES' => 'Productos de despensa',
        'FRUTAS Y VERDURAS' => 'Productos frescos (exonerados de IGV)',
        'BEBIDAS' => 'Aguas, gaseosas y jugos',
        'LIMPIEZA' => 'Productos de limpieza del hogar',
        'EMPAQUES' => 'Bolsas y material de empaque',
        'SERVICIOS' => 'Servicios técnicos',
    ];

    /**
     * [categoría, código, descripción, unidad SUNAT, precio con IGV, costo, stock inicial, stock mínimo, extras]
     * extras: afectacion (por defecto 10 gravado), sunat (código de producto SUNAT), icbper (S/ por unidad), servicio.
     */
    private const PRODUCTOS = [
        ['TECNOLOGIA', 'TEC-001', 'LAPTOP 14 PULGADAS CORE I5 16GB RAM 512GB SSD', 'NIU', 2899.00, 2150.00, 8, 2, ['sunat' => '43211503']],
        ['TECNOLOGIA', 'TEC-002', 'MONITOR LED 24 PULGADAS FULL HD', 'NIU', 589.00, 420.00, 12, 3, ['sunat' => '43211902']],
        ['TECNOLOGIA', 'TEC-003', 'IMPRESORA MULTIFUNCIONAL TINTA CONTINUA', 'NIU', 749.00, 560.00, 6, 2, []],
        ['ACCESORIOS DE COMPUTO', 'ACC-001', 'TECLADO USB ESPAÑOL', 'NIU', 45.00, 28.00, 40, 10, []],
        ['ACCESORIOS DE COMPUTO', 'ACC-002', 'MOUSE INALAMBRICO 2.4GHZ', 'NIU', 39.90, 22.00, 50, 10, []],
        ['ACCESORIOS DE COMPUTO', 'ACC-003', 'AUDIFONOS CON MICROFONO USB', 'NIU', 79.00, 48.00, 25, 5, []],
        ['ACCESORIOS DE COMPUTO', 'ACC-004', 'MEMORIA USB 64GB', 'NIU', 35.00, 19.50, 60, 15, []],
        ['ACCESORIOS DE COMPUTO', 'ACC-005', 'CABLE HDMI 2 METROS', 'NIU', 25.00, 11.00, 45, 10, []],
        ['OFICINA Y PAPELERIA', 'OFI-001', 'PAPEL BOND A4 80G PAQUETE X 500 HOJAS', 'PK', 22.50, 16.00, 120, 30, []],
        ['OFICINA Y PAPELERIA', 'OFI-002', 'LAPICERO AZUL CAJA X 12', 'BX', 15.00, 9.00, 80, 20, []],
        ['OFICINA Y PAPELERIA', 'OFI-003', 'CUADERNO A4 CUADRICULADO 100 HOJAS', 'NIU', 8.50, 5.20, 150, 30, []],
        ['OFICINA Y PAPELERIA', 'OFI-004', 'ARCHIVADOR DE PALANCA LOMO ANCHO', 'NIU', 9.90, 6.10, 70, 15, []],
        ['ABARROTES', 'ABA-001', 'ARROZ EXTRA BOLSA 5 KG', 'NIU', 24.90, 19.80, 60, 15, []],
        ['ABARROTES', 'ABA-002', 'AZUCAR RUBIA BOLSA 1 KG', 'NIU', 4.80, 3.70, 100, 25, []],
        ['ABARROTES', 'ABA-003', 'ACEITE VEGETAL BOTELLA 1 L', 'NIU', 10.50, 8.10, 80, 20, []],
        ['FRUTAS Y VERDURAS', 'FRU-001', 'PAPA BLANCA', 'KGM', 2.80, 1.90, 200, 40, ['afectacion' => '20']],
        ['FRUTAS Y VERDURAS', 'FRU-002', 'MANZANA DELICIA', 'KGM', 5.50, 3.80, 120, 30, ['afectacion' => '20']],
        ['FRUTAS Y VERDURAS', 'FRU-003', 'PLATANO DE SEDA', 'KGM', 3.90, 2.60, 150, 30, ['afectacion' => '20']],
        ['BEBIDAS', 'BEB-001', 'AGUA MINERAL SIN GAS 625 ML', 'NIU', 2.00, 1.10, 240, 48, []],
        ['BEBIDAS', 'BEB-002', 'GASEOSA 1.5 L', 'NIU', 7.50, 5.20, 120, 24, []],
        ['BEBIDAS', 'BEB-003', 'JUGO DE NARANJA 1 L', 'NIU', 6.90, 4.60, 60, 12, []],
        ['LIMPIEZA', 'LIM-001', 'DETERGENTE EN POLVO 1 KG', 'NIU', 12.90, 9.40, 70, 15, []],
        ['LIMPIEZA', 'LIM-002', 'LEJIA BOTELLA 1 L', 'NIU', 4.50, 2.90, 90, 20, []],
        ['LIMPIEZA', 'LIM-003', 'PAPEL HIGIENICO PAQUETE X 4', 'PK', 6.90, 4.70, 110, 25, []],
        ['EMPAQUES', 'EMP-001', 'BOLSA PLASTICA MEDIANA', 'NIU', 0.10, 0.04, 1000, 200, ['icbper' => 0.50]],
        ['SERVICIOS', 'SER-001', 'MANTENIMIENTO PREVENTIVO DE COMPUTADORA', 'ZZ', 80.00, 0, 0, 0, ['servicio' => true]],
        ['SERVICIOS', 'SER-002', 'INSTALACION DE SISTEMA OPERATIVO Y PROGRAMAS', 'ZZ', 60.00, 0, 0, 0, ['servicio' => true]],
        ['SERVICIOS', 'SER-003', 'SOPORTE TECNICO POR HORA', 'HUR', 50.00, 0, 0, 0, ['servicio' => true]],
    ];

    /** [código de producto, nombre, unidad SUNAT, factor (unidades base), precio con IGV] */
    private const PRESENTACIONES = [
        ['BEB-001', 'CAJA X 15 UNIDADES', 'BX', 15, 27.00],
        ['BEB-002', 'PAQUETE X 6 UNIDADES', 'PK', 6, 42.00],
        ['OFI-001', 'CAJA X 5 PAQUETES', 'BX', 5, 105.00],
        ['ABA-002', 'SACO X 50 BOLSAS', 'NIU', 50, 225.00],
    ];

    /** [tipo de documento (6 RUC, 1 DNI), número, razón social / nombre, dirección] — datos ficticios salvo los RUC públicos. */
    private const CLIENTES = [
        ['6', '20131312955', 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT', 'AV. GARCILASO DE LA VEGA NRO. 1472 LIMA - LIMA - LIMA'],
        ['6', '20100164958', 'EMPRESA MUNICIPAL DE MERCADOS SA', 'AV. LA CULTURA NRO 808 COO. SANTA AURELIA LIMA - LIMA - ATE'],
        ['6', '20100047218', 'BANCO DE CREDITO DEL PERU', 'CAL. CENTENARIO NRO. 156 URB. LAS LADERAS DE MELGAREJO LIMA - LIMA - LA MOLINA'],
        ['1', '40000001', 'CLIENTE DEMO UNO', 'AV. EJEMPLO 123, LIMA'],
        ['1', '40000002', 'CLIENTE DEMO DOS', 'JR. MUESTRA 456, LIMA'],
    ];

    public function run(InventarioService $inventario, ImagenProductoService $imagenes): void
    {
        $ruc = env('DEMO_EMPRESA_RUC');
        $empresa = $ruc ? Empresa::where('ruc', $ruc)->first() : Empresa::orderBy('id')->first();
        if (! $empresa) {
            $this->command->error($ruc
                ? "No existe una empresa con RUC $ruc."
                : 'No hay empresas registradas. Registra la empresa desde el sistema (Configuración → Empresas) y vuelve a ejecutar este seeder.');

            return;
        }
        $this->command->info("Empresa: {$empresa->razon_social} ({$empresa->ruc})");

        // Categorías
        $cats = [];
        foreach (self::CATEGORIAS as $nombre => $descripcion) {
            $cats[$nombre] = Categoria::withoutGlobalScopes()->firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                ['descripcion' => $descripcion, 'activo' => true],
            )->id;
        }
        $this->command->line('  Categorías: '.count($cats));

        // Productos
        $creados = 0;
        $productos = [];
        foreach (self::PRODUCTOS as [$cat, $codigo, $descripcion, $unidad, $precio, $costo, $stock, $minimo, $x]) {
            $servicio = $x['servicio'] ?? false;
            $p = Producto::withoutGlobalScopes()->withTrashed()->where('empresa_id', $empresa->id)->where('codigo', $codigo)->first();
            if (! $p) {
                $p = Producto::withoutGlobalScopes()->create([
                    'empresa_id' => $empresa->id, 'categoria_id' => $cats[$cat], 'tipo' => $servicio ? 'servicio' : 'bien',
                    'codigo' => $codigo, 'cod_producto_sunat' => $x['sunat'] ?? null, 'descripcion' => $descripcion, 'unidad' => $unidad,
                    'moneda' => 'PEN', 'precio_venta' => $precio, 'costo' => $costo, 'tip_afe_igv' => $x['afectacion'] ?? '10',
                    'aplica_icbper' => isset($x['icbper']), 'factor_icbper' => $x['icbper'] ?? null,
                    'controla_stock' => ! $servicio, 'stock_minimo' => $minimo, 'activo' => true,
                ]);
                $creados++;
            }
            $productos[$codigo] = ['modelo' => $p, 'stock' => $stock, 'costo' => $costo];
        }
        $this->command->line('  Productos: '.count($productos)." ($creados nuevos)");

        // Presentaciones
        foreach (self::PRESENTACIONES as [$codigo, $nombre, $unidad, $factor, $precio]) {
            ProductoPresentacion::firstOrCreate(
                ['producto_id' => $productos[$codigo]['modelo']->id, 'nombre' => $nombre],
                ['unidad' => $unidad, 'factor' => $factor, 'precio_venta' => $precio, 'activo' => true],
            );
        }
        $this->command->line('  Presentaciones: '.count(self::PRESENTACIONES));

        // Clientes
        foreach (self::CLIENTES as [$tipo, $numero, $nombre, $direccion]) {
            Cliente::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['empresa_id' => $empresa->id, 'tipo_documento' => $tipo, 'numero_documento' => $numero],
                ['razon_social' => $nombre, 'direccion' => $direccion, 'activo' => true],
            );
        }
        $this->command->line('  Clientes: '.count(self::CLIENTES));

        // Imágenes: ilustración (color e ícono de la categoría) para todo producto sin foto; no toca las fotos subidas.
        $sinImagen = Producto::withoutGlobalScopes()->with('categoria')->where('empresa_id', $empresa->id)->whereNull('imagen')->whereNull('deleted_at')->get();
        $sinImagen->each(fn (Producto $p) => $imagenes->generar($p));
        $this->command->line('  Imágenes generadas: '.$sinImagen->count());

        // Stock inicial en TODOS los almacenes (se crea el "Almacén principal" de cada establecimiento si aún no existe)
        // para TODOS los bienes de la empresa que controlan stock y no tienen existencias: los de demostración con su
        // cantidad de referencia y los creados por el usuario con el doble de su stock mínimo (mínimo 10).
        $almacenes = $inventario->almacenes($empresa);
        if ($almacenes->isEmpty()) {
            $this->command->warn('  Sin establecimientos: sincroniza Configuración → Establecimientos y series y vuelve a ejecutar el seeder para cargar el stock.');

            return;
        }
        $usuario = User::where('is_superadmin', true)->orderBy('id')->first() ?? User::orderBy('id')->first();
        if (! $usuario) {
            $this->command->warn('  Sin usuarios: registra el superadministrador y vuelve a ejecutar el seeder para cargar el stock.');

            return;
        }
        $demo = collect($productos)->mapWithKeys(fn ($d) => [$d['modelo']->id => $d]);
        $bienes = Producto::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('controla_stock', true)
            ->where('activo', true)->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($almacenes as $almacen) {
            $items = [];
            foreach ($bienes as $p) {
                $actual = (float) Stock::withoutGlobalScopes()->where('almacen_id', $almacen->id)->where('producto_id', $p->id)->value('cantidad');
                if ($actual > 0) {
                    continue;
                }
                $cantidad = isset($demo[$p->id]) && $demo[$p->id]['stock'] > 0 ? $demo[$p->id]['stock'] : max(10, (float) $p->stock_minimo * 2);
                $items[] = ['producto_id' => $p->id, 'cantidad' => $cantidad, 'costo_unitario' => (float) $p->costo];
            }
            if ($items) {
                $inventario->registrar($empresa, $usuario, $almacen, 'entrada', $items, 'INV-DEMO', 'Stock inicial de demostración');
            }
            $this->command->line("  {$almacen->nombre} (#{$almacen->id}): ".count($items).' productos con entrada de stock');
        }
    }
}
