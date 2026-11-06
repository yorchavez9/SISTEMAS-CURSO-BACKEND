# Arquitectura

## Estructura del repositorio

```
backend/                Laravel 12
  app/Core/             Piezas transversales
    Tenancy/            EmpresaActual, middleware ResolveEmpresa, trait BelongsToEmpresa
    Auditoria/          Auditor (registro de acciones sensibles)
    Permisos/           Catálogo de permisos y plantillas de roles
  app/Integrations/SunatApi/   Cliente HTTP de la API, limitador de solicitudes, excepciones
  app/Modules/<Módulo>/        Http (controladores, requests, resources), Services, Jobs…
    Auth, Usuarios, Empresas, Clientes, Productos, Tributos, Ventas,
    Facturacion, Caja, Inventario, Reportes
  app/Models/           Modelos Eloquent
  database/migrations/  Esquema (nunca se editan migraciones ya aplicadas: se agregan nuevas)
  tests/                Feature y Unit; tests/Fixtures/etapa1 = respuestas reales de la API
frontend/               React + TypeScript + Vite
  src/lib/              api.ts (axios + XSRF + empresa activa), types.ts
  src/features/<módulo>/  Páginas, diálogos y hooks de datos de cada módulo
  src/components/ui/    Componentes shadcn/ui
  src/components/shared/  PageHeader, buscador, paginador, formato de soles
  src/layouts/AppLayout.tsx  Menú lateral filtrado por permisos
docs/                   Esta documentación (en el repositorio del backend)
(tools/api-capture/     Captura real de la API de la Etapa 1: no se publica)
```

## Multiempresa

- Un usuario puede pertenecer a varias empresas con un rol distinto en cada una (spatie/laravel-permission con *teams*: el equipo es la empresa).
- El frontend envía la empresa activa en la cabecera `X-Empresa-Id`.
- El middleware `empresa[:permiso]` valida la pertenencia del usuario y el permiso, y fija `EmpresaActual` para la solicitud.
- Los modelos con el trait `BelongsToEmpresa` aplican un filtro global por empresa: una consulta no puede devolver datos de otra empresa por descuido.

## Autenticación y permisos

- Sanctum en modo SPA: sesión en cookie `httpOnly` y protección CSRF (`XSRF-TOKEN`). No se guardan tokens en el navegador.
- Permisos por módulo y acción (`comprobantes.emitir`, `caja.operar`…), definidos en `app/Core/Permisos/Permisos.php`.
- Roles base: **admin** (todo), **supervisor**, **vendedor** y **contador**. La API valida cada permiso; el menú del frontend solo oculta lo que el usuario no puede usar.

## Credenciales de la API

- Se guardan en `empresa_api_credenciales`, una fila por empresa y entorno (pruebas o producción), cifradas con `APP_KEY` mediante *casts* `encrypted`.
- Nunca se devuelven al frontend (solo los últimos 4 caracteres de la api_key y el resultado de la verificación), ni se escriben en logs ni en `api_solicitudes`.
- `SunatApiClientFactory::paraEmpresa()` arma el cliente con las credenciales del **entorno activo** de la empresa.

## Cliente de la API

`SunatApiClient`:

- envía las cabeceras `X-Api-Key` y `X-Api-Secret`;
- interpreta el envoltorio `{estado, mensaje, datos, errores}`;
- aplica un límite de 25 solicitudes por minuto por empresa, compartido entre procesos mediante la caché. La API admite 30 por minuto por cuenta;
- ante un HTTP 429 lanza `SunatApiRateLimitException` con el tiempo de espera, y ante errores de red, `SunatApiConnectionException`.

Toda llamada queda registrada en `api_solicitudes`.

## Cálculo tributario

`Modules/Tributos/Calculo/CalculadoraComprobante` calcula con `bcmath` (clase `Dec`) los valores unitarios, bases, IGV, ISC, ICBPER, descuentos, gratuitas, exportación y totales para todas las afectaciones del catálogo 07. Cuando el precio incluye IGV, el IGV se obtiene por diferencia.

Está validada con 47 casos reales aceptados por SUNAT en la Etapa 1 (`tests/Fixtures/etapa1`). El total que devuelve la API se compara con el local y cualquier diferencia queda marcada en el comprobante (`diferencia_totales`).

## Venta y comprobante

La **venta** (hecho comercial: detalle, pagos, cuotas, saldo, stock) y el **comprobante** (documento electrónico y su estado ante SUNAT) son entidades separadas.

```
POST /ventas ──► transacción MySQL
                  ├─ bloquea la serie y reserva el correlativo
                  ├─ crea venta, detalle, cuotas y comprobante (estado por_enviar, payload listo)
                  ├─ descuenta stock (kardex)
                  └─ registra pagos en la caja abierta
               commit ──► EmitirComprobanteJob (cola)
                              ├─ POST /facturas | /boletas
                              ├─ 422 "correlativo ya existe" → recupera el documento o renumera (máx. 3)
                              └─ ConsultarEstadoComprobanteJob (esperas crecientes: 5 s … 30 min)
                                     └─ aceptado → DescargarArchivosComprobanteJob (XML, CDR, PDF)
```

- **Idempotencia:** cada venta lleva una `idempotency_key`. Un doble clic o un reintento de red devuelve la misma venta.
- **Correlativo explícito:** siempre se envía el número reservado localmente, y la API rechaza los duplicados (hallazgo H-3).
- **Renumeración:** si el número ya lo usó otro sistema con la misma cuenta, se toma el siguiente libre según `GET /series` y se actualiza el kardex.
- **Nunca se reenvía** un comprobante que ya tiene `api_id`: solo se consulta su estado.

### Estados del comprobante

`por_enviar` → `enviado` → `aceptado` | `observado` | `rechazado`, además de `error` (la API no lo creó: hay que corregir y reintentar) y `anulado` (baja aceptada).

En el modo de resumen diario: `por_enviar` → `pendiente_resumen` → `enviado` (dentro del resumen) → `aceptado`.

## Notas, bajas y resúmenes

| Operación | Servicio | Endpoint de la API |
|---|---|---|
| Nota de crédito / débito | `NotaService` | `POST /notas-credito`, `/notas-debito` |
| Baja de facturas y sus notas (RA) | `BajaService` | `POST /anulaciones` (siempre con `fecha_comunicacion` de Lima, H-1) |
| Baja de boletas (RC de anulación) | `BajaService` | `POST /resumenes` con `anular` |
| Cotización (interna) | `CotizacionService` (módulo `Cotizaciones`) | No usa la API (H-13); la venta con `cotizacion_id` la marca convertida en la misma transacción |
| Guía de remisión remitente (09) | `GuiaService` (módulo `Guias`) | `POST /guias-remision`, `POST /guias-remision/{id}/enviar`, `GET /guias-remision/{id}/estado` |
| Envío de boletas (RC de envío) | `ResumenService` | `POST /resumenes` (`enviar_automatico:false`) + `POST /resumenes/{id}/enviar` |

Las comunicaciones se guardan en `comunicaciones` (con `tipo` RA o RC y `accion` anulacion o envio), y sus comprobantes en `comunicacion_items`.

Los efectos comerciales de una baja (venta anulada, reingreso de stock, saldo a favor) se aplican **solo cuando SUNAT la acepta**, y de forma idempotente.

## Caja e inventario

- **Caja:** una sesión abierta por caja (índice único). Los pagos y reembolsos son movimientos de la sesión; el cierre registra el arqueo. El saldo de la venta es: (total − notas de crédito + notas de débito) − (pagos − reembolsos); si la venta fue dada de baja, el neto es 0. Un saldo negativo es un saldo a favor del cliente.
- **Inventario:** el stock se lleva por almacén con costo promedio ponderado y cada movimiento queda en el kardex. La venta descuenta del almacén principal del establecimiento, salvo que la empresa permita stock negativo. Las notas de crédito por devolución y las bajas reingresan la mercadería al costo de salida.

## Reportes

`MetricasService` define qué cuenta como venta: estados `por_enviar`, `pendiente_resumen`, `enviado`, `aceptado` y `observado`, con signo negativo para las notas de crédito. El panel, los reportes y el registro de ventas usan la misma definición, por eso sus totales coinciden. En el registro, los anulados y rechazados figuran con importe cero.

## Decisiones principales

| Decisión | Motivo |
|---|---|
| Envíos solo por cola, después del commit | La venta nunca depende de la disponibilidad de SUNAT. Un fallo de red se reintenta sin duplicar |
| Credenciales en la base, cifradas | Pedido explícito: se administran desde el sistema y por empresa |
| Estructuras JSON tomadas de capturas reales | La colección de Postman no trae respuestas guardadas. No se inventan formatos |
| Resumen de envío solo para días anteriores | La API admite un único RC de envío por fecha (H-5) |
| Sin `/masivo` | Responde 422 aunque tenga éxito y expone SQL interno (H-6) |
| Código 37 deshabilitado | Falla en la API (H-11) |
