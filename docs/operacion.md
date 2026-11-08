# Manual de operación

## Estados de un comprobante

| Estado | Qué significa | Qué hacer |
|---|---|---|
| **Por enviar** | La venta está registrada pero la API de facturación no respondió al enviarla | Ábrelo o pulsa **Reintentar**: se reenvía sin duplicar. El sistema también lo reintenta cada minuto (cron) |
| **Pendiente de resumen** | Boleta registrada en la API, a la espera del resumen diario (modo resumen) | Se envía desde el día siguiente, de forma automática o desde **Resumen diario** |
| **En SUNAT** | Registrado en la API, que lo está enviando a SUNAT | Nada. Mientras el comprobante está abierto se consulta cada 3 segundos; si no, cada minuto (cron) |
| **Aceptado** | SUNAT lo aceptó; XML, CDR y PDF disponibles | — |
| **Observado** | Aceptado con observaciones | Revisa las notas en el detalle y corrige los datos maestros para las próximas ventas |
| **Rechazado** | SUNAT lo rechazó; no tiene validez | Emite un nuevo comprobante con los datos corregidos |
| **Error de envío** | La API no lo aceptó (validación) o falló tras varios intentos | Lee el error en el detalle. Si fue de red, pulsa **Reintentar** |
| **Anulado** | Dado de baja ante SUNAT (RA o RC aceptado) | — |

El detalle de cada comprobante (menú **Comprobantes**) muestra el historial de estados, cada llamada a la API y los archivos descargados.

## Corregir o anular una venta

| Situación | Herramienta | Plazo y condiciones |
|---|---|---|
| Anular toda la operación | **Baja** (RA para facturas y sus notas, RC para boletas) | Comprobante aceptado y dentro de los 7 días desde su emisión |
| Pasado el plazo de baja, anular o devolver todo | **Nota de crédito 01/02/06** | Comprobante aceptado |
| Devolver algunos ítems | Nota de crédito 07 (devuelve el stock) | |
| Descuento o corrección de monto | Nota de crédito 03/04/05/08/09/10 | La suma de las notas de crédito no puede superar el total (SUNAT 3286) |
| Cobrar intereses, penalidades o aumentar el valor | **Nota de débito** 01/02/03 | |

- Las bajas y las notas se solicitan desde el detalle del comprobante. Los efectos de una baja (venta anulada, reingreso de stock, saldo a favor) se aplican **solo cuando SUNAT la acepta**.
- Si el cliente ya había pagado, queda un **saldo a favor**, visible en **Cuentas por cobrar**. Se devuelve con **Reembolsar**, que requiere la caja abierta.

## Boletas: envío individual o por resumen diario

En **Comercial → Resumen diario** se elige el modo de la empresa:

- **Individual (predeterminado):** cada boleta se envía a SUNAT al emitirse, igual que las facturas.
- **Por resumen diario:** las boletas se registran en la API y se informan juntas en un resumen por fecha de emisión.
  - SUNAT admite **un solo resumen de envío por fecha**, por eso solo se envían fechas **anteriores a hoy**.
  - El envío es automático cada hora si el programador de tareas está activo (`schedule:work` o cron). También se puede enviar a mano desde la misma pantalla.
  - La API incluye en el resumen todas las boletas pendientes de esa fecha en la cuenta, también las de otro sistema que use las mismas credenciales. El sistema las muestra como "de otro sistema".
  - Si el resumen es rechazado, las boletas vuelven a **Pendiente de resumen** y se puede generar otro.

Las boletas pendientes de resumen cuentan como venta en el panel y en los reportes. No admiten notas ni baja hasta que el resumen se acepte.

## Cotizaciones

Menú **Comercial → Cotizaciones**. Una cotización es una propuesta de precio para el cliente: **no se envía a SUNAT y no es un comprobante de pago**.

1. **Nueva cotización:** elige un cliente registrado o escribe el nombre de un prospecto, agrega productos o ítems libres y fija la fecha de validez. Los totales se calculan con el mismo motor que las ventas, así que coinciden con lo que se facturará.
2. **Seguimiento:** Borrador → Enviada → Aceptada o Rechazada. Si pasa la fecha de validez sin cerrarse, se muestra como **Vencida** y ya no se puede aceptar ni convertir.
3. **Convertir en venta:** abre **Nueva venta** con el cliente, los ítems y los precios cotizados. Al emitir, la cotización queda **Convertida en venta** con el enlace a su comprobante. Una cotización se convierte una sola vez.
4. **Imprimir:** abre una hoja lista para imprimir o guardar como PDF desde el navegador.

Las cotizaciones no usan la API de facturación: su registro en la API falla por un defecto (hallazgo H-13).

## Imprimir comprobantes

Al registrar una venta se abre el detalle del comprobante con el botón **Imprimir**. La flecha a su lado permite elegir el formato:

| Formato | Uso |
|---|---|
| Ticket 80 mm | Impresora térmica de caja (predeterminado) |
| Ticket 58 mm | Impresora térmica angosta |
| A5 / A4 | Impresora de oficina |

- El formato elegido se recuerda en cada navegador, así que cada caja puede usar su propia impresora.
- El PDF lo genera la API en cuanto registra el comprobante, así que se puede entregar al cliente mientras SUNAT responde. También se pueden imprimir las boletas pendientes de resumen.
- No se imprimen comprobantes rechazados ni con error.

## Perfil de facturación (logo, certificado, mensajes del PDF)

**Configuración → Perfil de facturación** edita la cuenta de la empresa en la API de facturación (la del entorno activo). Lo que se guarda ahí aparece en el XML y en los PDF de los comprobantes nuevos:

| Pestaña | Qué se configura |
|---|---|
| Datos | Razón social, nombre comercial, dirección fiscal, ubigeo, hasta 5 teléfonos y 5 correos. También actualiza los datos locales de la empresa |
| Logo y PDF | Logo (PNG/JPG hasta 2 MB), mensaje de agradecimiento, mensaje promocional, hasta 5 cuentas bancarias (banco, tipo, moneda, número, CCI, titular) y 5 billeteras (Yape, Plin, Tunki u otra) |
| Certificado | Reemplazo del certificado digital (.pfx/.p12) con su contraseña, p. ej. al renovarlo cada año |
| Clave SOL y GRE | Usuario y Clave SOL; client_id y client_secret de la plataforma de guías (GRE). Vacío = conservar el actual |
| Régimen | General, MYPE, RER o Nuevo RUS (con categoría) y tasa de IGV manual. El cálculo del sistema toma la nueva tasa al guardar |

- Requiere el permiso **empresas.editar** para guardar; con **empresas.ver** se consulta en modo lectura.
- El certificado, su contraseña, la Clave SOL y el client_secret se envían directo a la API: **no se guardan en el sistema**, no se muestran y en el historial de llamadas figuran como `***`.
- Si otro sistema usa la misma cuenta de la API, verá los mismos cambios.

## Consulta de RUC y DNI

Al registrar un cliente, el sistema completa razón social, nombre y dirección consultando SUNAT (RUC) o RENIEC (DNI). El proveedor se elige en **Configuración → Credenciales API → Consulta de RUC y DNI**:

| Proveedor | Token | Cómo funciona |
|---|---|---|
| **API de facturación** (por defecto) | No necesita otro | `GET /buscar-documento` de la API de facturación, con sus mismas credenciales |
| **Decolecta** | Propio, de [decolecta.com/profile](https://decolecta.com/profile) | `GET https://api.decolecta.com/v1/sunat/ruc?numero=` y `/v1/reniec/dni?numero=` con `Authorization: Bearer <token>`. Si falla, se usa la API de facturación como respaldo |

- El token se guarda **cifrado** en la base (con `APP_KEY`), nunca vuelve al navegador (solo se muestran sus 4 últimos caracteres) y no aparece en la auditoría.
- **Probar** consulta el RUC público de SUNAT (20131312955) con el proveedor elegido, antes o después de guardar.
- Las consultas exitosas se guardan 24 horas en caché para no repetir llamadas.

## Retenciones y percepciones

**Comercial → Retenciones y percepciones** emite el comprobante de **retención (20)**, si la empresa es agente de retención, y el de **percepción (40)**, si es agente de percepción. Ambos se anulan con una **reversión (RR)**.

| | Retención | Percepción |
|---|---|---|
| Se emite a | Proveedor con RUC, al pagarle | Cliente, al cobrarle |
| Régimen y tasa | 01 = 3 % · 02 = 6 % | 01 = 2 % · 02 = 1 % (combustible) · 03 = 0.5 % |
| Importe | Retenido = total × tasa; neto pagado = total − retenido | Percibido = total × tasa; total cobrado = total + percibido |
| Archivos | PDF, XML y CDR | XML y CDR (la API no genera PDF de percepciones, H-16) |

- Se relacionan uno o varios comprobantes (factura, boleta o ticket) con su número `SERIE-NÚMERO`, su fecha y su importe total, en soles.
- El envío es inmediato. Si el número ya lo usó otro sistema con la misma cuenta, se renumera solo.
- **Revertir** (solo comprobantes aceptados) envía el RR. Cuando SUNAT lo acepta, el comprobante queda **Revertido**.
- Permisos: `retenciones.ver` para consultar y `retenciones.emitir` para emitir, reenviar y revertir. Los tienen el administrador y el supervisor; el contador solo consulta.
- Series: se crean en **Establecimientos y series** con prefijo `R` (retención) o `P` (percepción).

## Series y serie predeterminada

En **Configuración → Establecimientos y series**, cada serie tiene dos interruptores:

- **Activa:** se refleja en la API. Si otro sistema usa la misma cuenta, también deja de poder usar esa serie.
- **Predeterminada:** es la serie que se preselecciona al emitir. Hay una por tipo de comprobante; en notas, una para facturas (F) y otra para boletas (B). Es una preferencia **solo de este sistema** y no cambia nada en la API.

Si otro sistema emite con la misma cuenta, usa series propias para no compartir la numeración. En CLIENTE DEMO SAC se crearon y dejaron como predeterminadas F002, B002, FC02, FD02, BC02 y BD02.

## Guías de remisión

Menú **Comercial → Guías de remisión** (guía de remisión remitente, tipo 09). También se puede emitir desde el detalle de una factura o boleta aceptada con **Guía de remisión**: se precargan el destinatario, la dirección y los bienes (sin servicios).

| Modalidad | Datos obligatorios |
|---|---|
| Transporte privado | Placa y al menos un conductor (DNI, nombres, apellidos y licencia), salvo vehículo de categoría M1 o L |
| Transporte público | RUC y razón social del transportista (el registro MTC es opcional) |

- **Traslado entre establecimientos propios (motivo 04):** el destinatario es la propia empresa y se elige el establecimiento de destino.
- **Partida:** por defecto, la dirección del establecimiento de la serie.
- **Ubigeo:** se ingresa el código de 6 dígitos (por ejemplo, 150101 para Lima).
- La guía se envía a la API al registrarla, igual que los comprobantes. Al aceptarse se guardan su XML, CDR y PDF. Antes de la aceptación solo está disponible una vista previa del PDF.

## Caja

1. **Abrir caja** con el monto inicial antes de vender al contado.
2. Las ventas al contado registran los pagos (efectivo, tarjeta, Yape/Plin, transferencia…); el efectivo puede exceder el total y se calcula el vuelto.
3. Los cobros de ventas al crédito se registran desde **Cuentas por cobrar** y se aplican a la cuota más antigua.
4. **Cerrar caja** con el arqueo: el sistema muestra la diferencia entre lo esperado y lo contado.

Anular un pago exige tener la caja abierta y queda en la auditoría.

## Inventario

- Las ventas de bienes con control de stock descuentan del almacén principal del establecimiento. Si falta stock, la venta se bloquea, salvo que esté activa **Permitir vender sin stock** (pantalla Inventario).
- Registra las compras como **Entrada** con su costo: el costo promedio se recalcula solo.
- El **kardex** de cada producto muestra todos los movimientos con su documento de origen.

## Tareas periódicas

| Tarea | Cómo |
|---|---|
| Revisar el **Panel** al iniciar el día | Alertas de envíos atascados, errores, boletas sin resumen, stock bajo y saldos a favor |
| Cerrar caja al final del turno | Menú **Caja** |
| Revisar comprobantes con error | **Comprobantes**, filtro por estado |
| Exportar el registro de ventas del mes | **Reportes → Registro de ventas e impuestos → Excel** (o CSV) |

## Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| Comprobantes quedan en **Por enviar** | La API de facturación no respondió (caída, límite de solicitudes, credenciales) | Mira el aviso en el detalle y pulsa **Reintentar** |
| Alerta "más de 15 minutos sin respuesta de SUNAT" | Nadie abrió el comprobante y el cron `schedule:run` no corre, o SUNAT/beta lento | Abre el comprobante (consulta en el acto) o usa **Reintentar**; revisa el cron |
| Error "La API rechazó el envío (HTTP 422)…" | Dato inválido (p. ej. factura a cliente sin RUC) | Corrige el dato maestro y emite un nuevo comprobante |
| Historial con "Renumerado de F001-N a F001-M" | Otro sistema usó ese número con la misma cuenta | No requiere acción. Para evitarlo, usa series propias y márcalas como predeterminadas |
| "Credenciales inválidas" o HTTP 401 | Credenciales revocadas, o `APP_KEY` cambiada | Vuelve a ingresar las credenciales en **Credenciales API** |
| HTTP 429 en `api_solicitudes` | Límite de 30 solicitudes por minuto de la API | No requiere acción: el sistema espera y reintenta |
| Baja **RA rechazada con 2301** | Fecha de comunicación en UTC (H-1) | Ya corregido: el sistema siempre envía la fecha de Lima |
| Resumen con error "ya están incluidas en un resumen" | Otro sistema ya envió el resumen de esa fecha | El sistema consulta cada boleta en la API y adopta su estado; revisa el detalle |
| Guía en **Error de envío** con "Cliente No autorizado" (BUILD_ERROR) | La cuenta de la API perdió o no tiene las credenciales GRE de SUNAT | Configúralas en la cuenta de la API (`client_id` y `client_secret` de SOL) y pulsa **Reenviar**: se reenvía la misma guía, sin usar otro número |

## Auditoría

Se consulta en **Configuración → Auditoría** (permiso `auditoria.ver`: administrador y contador), con filtros por módulo, usuario y fechas. Las acciones de procesos automáticos (cola, programador) figuran como "Proceso automático".

Las acciones sensibles quedan en `audit_logs` con usuario, empresa, fecha, IP y valores antes y después: ingresos al sistema, empresa y credenciales de la API, usuarios y roles, clientes y productos, ventas, pagos, anulaciones de pagos y reembolsos, apertura y cierre de caja, movimientos de inventario, notas, bajas y resúmenes.
