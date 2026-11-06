# Sistema de ventas con facturación electrónica SUNAT — Backend

API en Laravel del sistema. El frontend (React) está en [SISTEMA-CURSO-FRONTEND](https://github.com/yorchavez9/SISTEMA-CURSO-FRONTEND).

Sistema de ventas multiempresa con emisión de comprobantes electrónicos (facturas, boletas, notas de crédito y débito, bajas y resúmenes diarios) a través de la API de facturación `https://sunat.kodevo.es/api/v1`.

| Capa | Tecnología |
|---|---|
| Backend | Laravel 12 (PHP 8.3), Sanctum (sesión SPA por cookie), spatie/laravel-permission (roles por empresa) |
| Frontend | React 19 + TypeScript + Vite, shadcn/ui, TanStack Query, react-hook-form + zod |
| Base de datos | MySQL 8.4 |
| Procesos | Cola de trabajos (envíos a SUNAT) y programador de tareas (resúmenes diarios) |

## Módulos

- **Empresas y configuración:** varias empresas por instalación, establecimientos y series sincronizados con la API, credenciales de la API cifradas en la base de datos, régimen y tasa de IGV.
- **Usuarios y roles:** administrador, supervisor, vendedor y contador, asignados por empresa. El primer acceso crea el superadministrador.
- **Comercial:** clientes (validación de RUC/DNI con la API), productos y servicios con presentaciones, ventas al contado y al crédito con cuotas.
- **Facturación electrónica:** facturas y boletas con todas las afectaciones de IGV validadas, notas de crédito y débito, comunicaciones de baja (RA), resúmenes de anulación y de envío de boletas (RC), descarga de XML, CDR y PDF.
- **Cotizaciones:** propuestas de precio con seguimiento de estado y conversión en venta con un clic. Se imprimen desde el navegador.
- **Guías de remisión:** guía remitente electrónica (tipo 09) con transporte público, privado o vehículo M1/L, y traslado entre establecimientos propios. Se puede emitir desde una factura o boleta.
- **Caja:** apertura, cobros con varios métodos de pago, vuelto, arqueo y cierre; cuentas por cobrar por antigüedad; reembolsos de saldos a favor.
- **Inventario:** almacenes, costo promedio ponderado, kardex, entradas, salidas, ajustes y transferencias. La venta descuenta stock en la misma transacción.
- **Panel y reportes:** venta neta, comprobantes por estado, productos y clientes principales, alertas, reportes agrupados y registro de ventas exportable a Excel (.xlsx) y CSV.
- **Auditoría:** registro de acciones sensibles por empresa y usuario.

## Documentación

| Documento | Contenido |
|---|---|
| [docs/instalacion.md](docs/instalacion.md) | Puesta en marcha en desarrollo (Windows/Laragon o Linux/macOS) |
| [docs/despliegue.md](docs/despliegue.md) | Producción: servidor web, cola, programador, Redis, respaldos, seguridad |
| [docs/arquitectura.md](docs/arquitectura.md) | Organización del código, multiempresa, flujo de emisión y decisiones técnicas |
| [docs/operacion.md](docs/operacion.md) | Manual de operación: estados de los comprobantes, bajas, resúmenes y solución de problemas |
| [docs/etapa1-resultados-api.md](docs/etapa1-resultados-api.md) | Resultados de la captura real de la API y hallazgos (H-1 … H-13) |

## Inicio rápido (desarrollo)

Requisitos: PHP 8.3 (con `bcmath`), Composer, MySQL 8.4. Crea las bases `sistema_ventas` y `sistema_ventas_test`.

```bash
git clone https://github.com/yorchavez9/SISTEMAS-CURSO-BACKEND.git backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate

php artisan serve --host=127.0.0.1 --port=8000   # API
php artisan queue:work                             # otra terminal: envíos a SUNAT (obligatorio)
php artisan schedule:work                          # otra terminal: resumen diario de boletas
```

Luego levanta el [frontend](https://github.com/yorchavez9/SISTEMA-CURSO-FRONTEND) (`npm install && npm run dev`, puerto 5174) y abre `http://localhost:5174`.

Al abrir el sistema por primera vez se pide registrar el superadministrador. Después: registrar la empresa, ingresar las credenciales de la API en **Configuración → Credenciales API** y sincronizar establecimientos y series.

> Las credenciales de la API **nunca** van en `.env`: se ingresan desde el sistema y se guardan cifradas con `APP_KEY`.

## Pruebas

```bash
php artisan test          # usa la base sistema_ventas_test (ver phpunit.xml)
```
