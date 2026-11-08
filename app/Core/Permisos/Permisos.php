<?php

namespace App\Core\Permisos;

/**
 * Catálogo de permisos por módulo y acción, y plantillas de roles.
 * Los roles son globales (empresa_id NULL) y se ASIGNAN por empresa (spatie teams = empresa).
 */
final class Permisos
{
    public const MODULOS = [
        'empresas' => ['label' => 'Empresas', 'acciones' => ['ver' => 'Ver', 'editar' => 'Editar datos de la empresa']],
        'credenciales_api' => ['label' => 'Credenciales API', 'acciones' => ['gestionar' => 'Ver, registrar y probar credenciales']],
        'usuarios' => ['label' => 'Usuarios', 'acciones' => ['ver' => 'Ver', 'gestionar' => 'Crear, editar roles y retirar usuarios']],
        'configuracion_tributaria' => ['label' => 'Configuración tributaria', 'acciones' => ['ver' => 'Ver catálogos y afectaciones']],
        'clientes' => ['label' => 'Clientes', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar']],
        'productos' => ['label' => 'Productos y servicios', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar']],
        'inventario' => ['label' => 'Inventario', 'acciones' => ['ver' => 'Ver kardex y stock', 'movimientos' => 'Registrar entradas, salidas y ajustes']],
        'ventas' => ['label' => 'Ventas', 'acciones' => ['ver' => 'Ver', 'crear' => 'Registrar ventas', 'anular' => 'Anular ventas']],
        'caja' => ['label' => 'Caja', 'acciones' => ['ver' => 'Ver cajas y arqueos', 'operar' => 'Abrir, cerrar y registrar movimientos']],
        'comprobantes' => ['label' => 'Comprobantes electrónicos', 'acciones' => ['ver' => 'Ver', 'emitir' => 'Emitir', 'anular' => 'Anular / emitir notas', 'descargar' => 'Descargar XML, CDR y PDF']],
        'cotizaciones' => ['label' => 'Cotizaciones', 'acciones' => ['ver' => 'Ver', 'gestionar' => 'Crear, cambiar estado y convertir en venta']],
        'guias' => ['label' => 'Guías de remisión', 'acciones' => ['ver' => 'Ver', 'emitir' => 'Emitir y reenviar']],
        'retenciones' => ['label' => 'Retenciones y percepciones', 'acciones' => ['ver' => 'Ver', 'emitir' => 'Emitir, reenviar y revertir']],
        'reportes' => ['label' => 'Reportes', 'acciones' => ['ver' => 'Ver y exportar']],
        'auditoria' => ['label' => 'Auditoría', 'acciones' => ['ver' => 'Ver registro de auditoría']],
    ];

    public const ROLES = [
        'admin' => ['label' => 'Administrador', 'permisos' => '*'],
        'supervisor' => ['label' => 'Supervisor', 'permisos' => [
            'empresas.ver', 'usuarios.ver', 'configuracion_tributaria.ver',
            'clientes.*', 'productos.*', 'inventario.*', 'ventas.*', 'caja.*', 'comprobantes.*', 'guias.*', 'retenciones.*', 'cotizaciones.*', 'reportes.ver',
        ]],
        'vendedor' => ['label' => 'Vendedor', 'permisos' => [
            'empresas.ver', 'clientes.ver', 'clientes.crear', 'clientes.editar', 'productos.ver', 'inventario.ver',
            'ventas.ver', 'ventas.crear', 'caja.operar', 'comprobantes.ver', 'comprobantes.emitir', 'comprobantes.descargar', 'guias.ver', 'guias.emitir', 'cotizaciones.ver', 'cotizaciones.gestionar',
        ]],
        'contador' => ['label' => 'Contador', 'permisos' => [
            'empresas.ver', 'configuracion_tributaria.ver', 'clientes.ver', 'productos.ver', 'inventario.ver',
            'ventas.ver', 'caja.ver', 'comprobantes.ver', 'comprobantes.descargar', 'guias.ver', 'retenciones.ver', 'reportes.ver', 'auditoria.ver',
        ]],
    ];

    /** @return list<string> */
    public static function todos(): array
    {
        $out = [];
        foreach (self::MODULOS as $mod => $def) {
            foreach (array_keys($def['acciones']) as $acc) {
                $out[] = "$mod.$acc";
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function delRol(string $rol): array
    {
        $def = self::ROLES[$rol]['permisos'];
        if ($def === '*') {
            return self::todos();
        }
        $out = [];
        foreach ($def as $p) {
            $out = array_merge($out, str_ends_with($p, '.*')
                ? array_values(array_filter(self::todos(), fn ($x) => str_starts_with($x, substr($p, 0, -1))))
                : [$p]);
        }

        return array_values(array_unique($out));
    }
}
