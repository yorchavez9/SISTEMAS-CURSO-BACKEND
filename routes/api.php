<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SetupController;
use App\Modules\Caja\Http\Controllers\CajaController;
use App\Modules\Clientes\Http\Controllers\ClienteController;
use App\Modules\Productos\Http\Controllers\ProductoController;
use App\Modules\Reportes\Http\Controllers\DashboardController;
use App\Modules\Reportes\Http\Controllers\ReporteController;
use App\Modules\Facturacion\Http\Controllers\ComprobanteController;
use App\Modules\Facturacion\Http\Controllers\BajaController;
use App\Modules\Facturacion\Http\Controllers\ResumenController;
use App\Modules\Guias\Http\Controllers\GuiaController;
use App\Modules\Auditoria\Http\Controllers\AuditoriaController;
use App\Modules\Cotizaciones\Http\Controllers\CotizacionController;
use App\Modules\Facturacion\Http\Controllers\NotaController;
use App\Modules\Inventario\Http\Controllers\InventarioController;
use App\Modules\Ventas\Http\Controllers\PreviewController;
use App\Modules\Ventas\Http\Controllers\VentaController;
use App\Modules\Empresas\Http\Controllers\CredencialApiController;
use App\Modules\Empresas\Http\Controllers\EmpresaController;
use App\Modules\Empresas\Http\Controllers\EstablecimientoSerieController;
use App\Modules\Tributos\Http\Controllers\CatalogoController;
use App\Modules\Usuarios\Http\Controllers\UsuarioEmpresaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Configuración inicial (primer superadministrador). Se cierra cuando existe algún usuario.
    Route::get('setup/estado', [SetupController::class, 'estado']);
    Route::post('setup/superadmin', [SetupController::class, 'registrarSuperadmin'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::apiResource('empresas', EmpresaController::class)->except('destroy');

        $entornos = ['pruebas', 'produccion'];
        Route::prefix('empresas/{empresa}/credenciales-api')->group(function () use ($entornos) {
            Route::get('/', [CredencialApiController::class, 'index']);
            Route::put('{entorno}', [CredencialApiController::class, 'update'])->middleware('throttle:credenciales')->whereIn('entorno', $entornos);
            Route::post('{entorno}/verificar', [CredencialApiController::class, 'verificar'])->middleware('throttle:credenciales')->whereIn('entorno', $entornos);
            Route::delete('{entorno}', [CredencialApiController::class, 'destroy'])->whereIn('entorno', $entornos);
        });

        Route::get('roles', [UsuarioEmpresaController::class, 'roles']);
        Route::get('empresas/{empresa}/usuarios', [UsuarioEmpresaController::class, 'index']);
        Route::post('empresas/{empresa}/usuarios', [UsuarioEmpresaController::class, 'store']);
        Route::put('empresas/{empresa}/usuarios/{usuario}', [UsuarioEmpresaController::class, 'update']);
        Route::delete('empresas/{empresa}/usuarios/{usuario}', [UsuarioEmpresaController::class, 'destroy']);

        Route::get('catalogos', [CatalogoController::class, 'index']);

        // ---- Módulos de la empresa activa (header X-Empresa-Id) ----
        Route::get('establecimientos', [EstablecimientoSerieController::class, 'index'])->middleware('empresa:empresas.ver');
        Route::middleware(['empresa:empresas.editar', 'throttle:credenciales'])->group(function () {
            Route::post('establecimientos/sincronizar', [EstablecimientoSerieController::class, 'sincronizar']);
            Route::post('establecimientos', [EstablecimientoSerieController::class, 'storeEstablecimiento']);
            Route::post('series', [EstablecimientoSerieController::class, 'storeSerie']);
            Route::put('series/{serie}', [EstablecimientoSerieController::class, 'updateSerie']);
        });

        // Clientes
        Route::get('clientes', [ClienteController::class, 'index'])->middleware('empresa:clientes.ver');
        Route::get('clientes/consultar-documento', [ClienteController::class, 'consultarDocumento'])->middleware(['empresa:clientes.ver', 'throttle:60,1']);
        Route::get('clientes/{cliente}', [ClienteController::class, 'show'])->middleware('empresa:clientes.ver');
        Route::post('clientes', [ClienteController::class, 'store'])->middleware('empresa:clientes.crear');
        Route::put('clientes/{cliente}', [ClienteController::class, 'update'])->middleware('empresa:clientes.editar');
        Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])->middleware('empresa:clientes.eliminar');

        // Productos y categorías
        Route::get('productos', [ProductoController::class, 'index'])->middleware('empresa:productos.ver');
        Route::get('productos/codigos-sunat', [ProductoController::class, 'codigosSunat'])->middleware(['empresa:productos.ver', 'throttle:60,1']);
        Route::get('productos/{producto}', [ProductoController::class, 'show'])->middleware('empresa:productos.ver');
        Route::post('productos', [ProductoController::class, 'store'])->middleware('empresa:productos.crear');
        Route::put('productos/{producto}', [ProductoController::class, 'update'])->middleware('empresa:productos.editar');
        Route::delete('productos/{producto}', [ProductoController::class, 'destroy'])->middleware('empresa:productos.eliminar');
        Route::get('categorias', [ProductoController::class, 'categorias'])->middleware('empresa:productos.ver');
        Route::post('categorias', [ProductoController::class, 'storeCategoria'])->middleware('empresa:productos.crear');
        Route::delete('categorias/{categoria}', [ProductoController::class, 'destroyCategoria'])->middleware('empresa:productos.eliminar');

        // Vista previa de cálculo (motor validado con la Etapa 1)
        Route::post('ventas/preview', PreviewController::class)->middleware('empresa:ventas.crear');

        // Ventas y comprobantes electrónicos
        Route::get('ventas', [VentaController::class, 'index'])->middleware('empresa:ventas.ver');
        Route::post('ventas', [VentaController::class, 'store'])->middleware(['empresa:comprobantes.emitir', 'throttle:120,1']);
        Route::get('comprobantes', [ComprobanteController::class, 'index'])->middleware('empresa:comprobantes.ver');
        Route::get('comprobantes/{comprobante}', [ComprobanteController::class, 'show'])->middleware('empresa:comprobantes.ver');
        Route::get('comprobantes/{comprobante}/imprimir/{formato}', [ComprobanteController::class, 'imprimir'])
            ->whereIn('formato', ['ticket-80', 'ticket-58', 'a5', 'a4'])->middleware(['empresa:comprobantes.descargar', 'throttle:60,1']);
        Route::get('comprobantes/{comprobante}/archivos/{tipo}', [ComprobanteController::class, 'archivo'])
            ->whereIn('tipo', ['xml', 'cdr', 'pdf'])->middleware('empresa:comprobantes.descargar');
        Route::post('comprobantes/{comprobante}/reintentar', [ComprobanteController::class, 'reintentar'])->middleware('empresa:comprobantes.emitir');

        // Notas de crédito y débito
        Route::get('comprobantes/{comprobante}/notas/opciones', [NotaController::class, 'opciones'])->middleware('empresa:comprobantes.anular');
        Route::post('notas', [NotaController::class, 'store'])->middleware(['empresa:comprobantes.anular', 'throttle:60,1']);

        // Bajas: comunicación de baja (RA) y resumen diario de anulación (RC)
        Route::get('bajas', [BajaController::class, 'index'])->middleware('empresa:comprobantes.ver');
        Route::post('bajas', [BajaController::class, 'store'])->middleware(['empresa:comprobantes.anular', 'throttle:30,1']);
        Route::post('bajas/{comunicacion}/reintentar', [BajaController::class, 'reintentar'])->middleware('empresa:comprobantes.anular');
        Route::get('bajas/{comunicacion}/archivos/{tipo}', [BajaController::class, 'archivo'])->whereIn('tipo', ['xml', 'cdr'])
            ->middleware(['empresa:comprobantes.descargar', 'throttle:60,1']);

        // Resumen diario de ENVÍO de boletas (empresas con envio_boletas = resumen)
        Route::get('resumenes/pendientes', [ResumenController::class, 'pendientes'])->middleware('empresa:comprobantes.ver');
        Route::post('resumenes', [ResumenController::class, 'store'])->middleware(['empresa:comprobantes.emitir', 'throttle:30,1']);
        Route::post('resumenes/{comunicacion}/reintentar', [BajaController::class, 'reintentar'])->middleware('empresa:comprobantes.emitir');

        // Cotizaciones (documento interno; solo en el sistema por H-13)
        Route::get('cotizaciones', [CotizacionController::class, 'index'])->middleware('empresa:cotizaciones.ver');
        Route::post('cotizaciones', [CotizacionController::class, 'store'])->middleware(['empresa:cotizaciones.gestionar', 'throttle:60,1']);
        Route::get('cotizaciones/{cotizacion}', [CotizacionController::class, 'show'])->middleware('empresa:cotizaciones.ver');
        Route::put('cotizaciones/{cotizacion}/estado', [CotizacionController::class, 'estado'])->middleware('empresa:cotizaciones.gestionar');

        // Auditoría (solo lectura)
        Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('empresa:auditoria.ver');
        Route::get('auditoria/filtros', [AuditoriaController::class, 'filtros'])->middleware('empresa:auditoria.ver');

        // Guías de remisión remitente (09)
        Route::get('guias/opciones', [GuiaController::class, 'opciones'])->middleware('empresa:guias.emitir');
        Route::get('guias', [GuiaController::class, 'index'])->middleware('empresa:guias.ver');
        Route::post('guias', [GuiaController::class, 'store'])->middleware(['empresa:guias.emitir', 'throttle:60,1']);
        Route::get('guias/{guia}', [GuiaController::class, 'show'])->middleware('empresa:guias.ver');
        Route::post('guias/{guia}/reintentar', [GuiaController::class, 'reintentar'])->middleware('empresa:guias.emitir');
        Route::get('guias/{guia}/archivos/{tipo}', [GuiaController::class, 'archivo'])->whereIn('tipo', ['xml', 'cdr', 'pdf'])->middleware('empresa:guias.ver');

        // Caja, pagos y cuentas por cobrar
        Route::get('caja/estado', [CajaController::class, 'estado'])->middleware('empresa:caja.operar');
        Route::post('caja/abrir', [CajaController::class, 'abrir'])->middleware('empresa:caja.operar');
        Route::post('caja/movimientos', [CajaController::class, 'movimiento'])->middleware('empresa:caja.operar');
        Route::post('caja/cerrar', [CajaController::class, 'cerrar'])->middleware('empresa:caja.operar');
        Route::get('caja/sesiones', [CajaController::class, 'sesiones'])->middleware('empresa:caja.ver');
        Route::get('caja/sesiones/{sesion}', [CajaController::class, 'sesion'])->middleware('empresa:caja.ver');
        Route::get('ventas/{venta}/pagos', [CajaController::class, 'pagosVenta'])->middleware('empresa:ventas.ver');
        Route::post('ventas/{venta}/pagos', [CajaController::class, 'cobrar'])->middleware('empresa:caja.operar');
        Route::post('pagos/{pago}/anular', [CajaController::class, 'anularPago'])->middleware('empresa:caja.operar');
        Route::get('cuentas-por-cobrar', [CajaController::class, 'cuentasPorCobrar'])->middleware('empresa:ventas.ver');
        Route::post('ventas/{venta}/reembolso', [CajaController::class, 'reembolsar'])->middleware('empresa:caja.operar');

        // Dashboard y reportes
        Route::get('dashboard', DashboardController::class)->middleware('empresa:ventas.ver');
        Route::get('reportes/ventas', [ReporteController::class, 'ventas'])->middleware('empresa:reportes.ver');
        Route::get('reportes/registro-ventas', [ReporteController::class, 'registro'])->middleware('empresa:reportes.ver');
        Route::get('reportes/registro-ventas/excel', [ReporteController::class, 'registroExcel'])->middleware(['empresa:reportes.ver', 'throttle:30,1']);

        // Inventario
        Route::get('almacenes', [InventarioController::class, 'almacenes'])->middleware('empresa:inventario.ver');
        Route::post('almacenes', [InventarioController::class, 'storeAlmacen'])->middleware('empresa:inventario.movimientos');
        Route::get('inventario/stock', [InventarioController::class, 'stock'])->middleware('empresa:inventario.ver');
        Route::get('inventario/kardex/{producto}', [InventarioController::class, 'kardex'])->middleware('empresa:inventario.ver');
        Route::post('inventario/movimientos', [InventarioController::class, 'movimiento'])->middleware('empresa:inventario.movimientos');
        Route::post('inventario/transferencias', [InventarioController::class, 'transferencia'])->middleware('empresa:inventario.movimientos');
    });
});
