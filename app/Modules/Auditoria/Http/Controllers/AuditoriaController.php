<?php

namespace App\Modules\Auditoria\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta del registro de auditoría de la empresa activa (solo lectura). */
class AuditoriaController extends Controller
{
    /** Nombre legible de cada evento registrado por el sistema. */
    public const EVENTOS = [
        'empresa.creada' => 'Empresa creada', 'empresa.actualizada' => 'Empresa actualizada',
        'credenciales_api.creadas' => 'Credenciales API registradas', 'credenciales_api.actualizadas' => 'Credenciales API actualizadas',
        'credenciales_api.verificadas' => 'Credenciales API probadas', 'credenciales_api.eliminadas' => 'Credenciales API eliminadas',
        'sincronizacion_api.importada' => 'Establecimientos y series sincronizados',
        'usuario.creado' => 'Usuario creado', 'usuario.agregado_a_empresa' => 'Usuario agregado a la empresa', 'usuario.actualizado' => 'Usuario actualizado', 'usuario.retirado_de_empresa' => 'Usuario retirado de la empresa',
        'cliente.creado' => 'Cliente creado', 'cliente.actualizado' => 'Cliente actualizado', 'cliente.eliminado' => 'Cliente eliminado',
        'producto.creado' => 'Producto creado', 'producto.actualizado' => 'Producto actualizado', 'producto.eliminado' => 'Producto eliminado',
        'venta.registrada' => 'Venta registrada', 'comprobante.reintento' => 'Reintento de envío de comprobante',
        'nota_credito.emitida' => 'Nota de crédito emitida', 'nota_debito.emitida' => 'Nota de débito emitida',
        'baja.solicitada' => 'Baja solicitada', 'baja.aceptada' => 'Baja aceptada por SUNAT',
        'resumen.solicitado' => 'Resumen diario solicitado', 'resumen.aceptado' => 'Resumen diario aceptado por SUNAT',
        'cotizacion.creada' => 'Cotización creada', 'cotizacion.estado' => 'Cambio de estado de cotización',
        'serie.creada' => 'Serie creada', 'serie.actualizada' => 'Serie actualizada',
        'guia.emitida' => 'Guía de remisión emitida', 'guia.reintento' => 'Reenvío de guía de remisión',
        'caja.abierta' => 'Caja abierta', 'caja.cerrada' => 'Caja cerrada', 'caja.movimiento' => 'Movimiento de caja',
        'pagos.registrados' => 'Pagos registrados', 'pago.anulado' => 'Pago anulado', 'pago.reembolso' => 'Reembolso',
        'inventario.entrada' => 'Entrada de inventario', 'inventario.salida' => 'Salida de inventario', 'inventario.ajuste' => 'Ajuste de inventario',
        'inventario.transferencia' => 'Transferencia de inventario',
    ];

    public function __construct(private readonly EmpresaActual $empresa) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'modulo' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'user_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $e = $this->empresa->id();
        // Las fechas se filtran en hora de Lima; created_at se guarda en la zona de la aplicación (America/Lima).
        $p = AuditLog::query()->where('empresa_id', $e)
            ->when($f['modulo'] ?? null, fn ($q, $m) => $q->where('evento', 'like', "$m.%"))
            ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($f['desde'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', "$d 00:00:00"))
            ->when($f['hasta'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', "$d 23:59:59"))
            ->orderByDesc('id')->paginate(25);
        $usuarios = User::query()->whereIn('id', $p->getCollection()->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'data' => $p->getCollection()->map(fn (AuditLog $a) => [
                'id' => $a->id, 'fecha' => $a->created_at?->toIso8601String(), 'evento' => $a->evento,
                'descripcion' => self::EVENTOS[$a->evento] ?? $a->evento,
                'usuario' => $a->user_id ? ($usuarios[$a->user_id] ?? "Usuario #{$a->user_id}") : null,
                'objeto' => $a->auditable_type ? class_basename($a->auditable_type).' #'.$a->auditable_id : null,
                'antes' => $a->old_values, 'despues' => $a->new_values, 'ip' => $a->ip,
            ]),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    /** Opciones de los filtros: módulos con eventos en la empresa y usuarios que aparecen en el registro. */
    public function filtros(): JsonResponse
    {
        $e = $this->empresa->id();
        $modulos = AuditLog::query()->where('empresa_id', $e)->selectRaw("DISTINCT SUBSTRING_INDEX(evento, '.', 1) m")->pluck('m')->sort()->values();
        $usuarios = User::query()->whereIn('id', AuditLog::query()->where('empresa_id', $e)->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => ['modulos' => $modulos, 'usuarios' => $usuarios]]);
    }
}
