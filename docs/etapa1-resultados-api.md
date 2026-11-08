# Etapa 1: resultados reales de la API SUNAT (kodevo)

- **Fecha de ejecución:** 2026-11-05, hora de Lima.
- **Empresa de pruebas:** RUC 20161515648, entorno `beta`, régimen MYPE (IGV 18 %), plan `pro`.
- **Herramienta:** `php artisan sunat:captura` (código en `tools/api-capture/`, que no se publica en el repositorio porque contiene respuestas reales). Las credenciales salen de la base de datos y van cifradas.
- **Evidencia:**
  - 250 capturas en `tools/api-capture/output/responses/`;
  - matriz automática en `tools/api-capture/output/MATRIZ.md`;
  - XML, CDR y PDF descargados en `output/files/`.

> Todo lo que aparece como **aceptado** fue respondido por SUNAT beta con código `0`. Lo **no ejecutado** o **pendiente** se indica de forma explícita.

## 1. Resumen

| Grupo | Escenarios Postman | Aceptados por SUNAT | Observación |
|---|---|---|---|
| Facturas (01) | 24 | **20 + F-01 re-probada** | F-01 y F-21 fallaron por correlativos ya usados. F-01 se volvió a probar con el correlativo 950 y fue aceptada. |
| Boletas (03) | 8 | 5 (B-02, B-03, B-07, B-08 y B-06 vía RC) | B-01 se eliminó en la prueba de DELETE. B-04 y B-05 **no se ejecutaron** (requieren cambiar el régimen). |
| Notas de crédito (07) | 6 | **6** | — |
| Notas de débito (08) | 5 | **5** | — |
| Resumen diario (RC) | 3 | 2 (RC-02 anulación, RC-03 envío) | RC-01: 422 legítimo, porque las boletas del día ya estaban en RC-03. |
| Comunicación de baja (RA) | 4 | 2 (RA-01, RA-02 en lote 01+01+07+08) | RA-03 y RA-04 **rechazadas por SUNAT 2301** (defecto de la API, ver H-1). |
| Guía remitente (09) | 6 | 0 | `BUILD_ERROR "Cliente No autorizado"` (configuración GRE de la cuenta, ver H-7). GRR-04: regla de destinatario. |
| GRT, retención, percepción, RR, liquidación de compra | 25 | no ejecutados | Opcionales (D4). |

**Facturas aceptadas** (F-02 a F-20 y F-22 a F-24): crédito con cuotas, exonerada, exportación en USD con cliente sin documento, inafecta, gratuita (11), detracción, anticipo, descuento global 02, descuento por línea 00, descuento 03 que no afecta la base, ICBPER, ISC, IVAP 4 %, con guía, mixta (10+20+30+13+32), ICBPER + gratuito (13), envío manual y percepciones 0.5 %, 1 % y 2 %.

## 2. Operaciones de seguimiento verificadas

| Operación | Resultado |
|---|---|
| `GET /{tipo}/{id}`, listados con filtros y paginación | ✔ |
| XML (`application/xml`), CDR (`application/zip`) | ✔ facturas, boletas, NC, ND, RC y RA |
| PDF `a4`, `a5`, `ticket-80`, `ticket-58` (acepta `format` y también `formato`) | ✔ |
| `POST /{tipo}/{id}/enviar` y `/reenviar` | ✔ facturas, NC, ND, RC, RA y GRR |
| `PUT /boletas/{id}` (corrige y reenvía) | ✔ aceptada tras el reenvío |
| `DELETE /boletas/{id}` (no aceptada) | ✔ "Boleta eliminada localmente." |
| Pagos: registrar, listar y eliminar (factura y boleta) | ✔ con recálculo de `estado_pago` y `monto_pagado` |
| `GET /resumenes/{id}/estado`, `/anulaciones/{id}/estado` | ✔ |
| Correlativo explícito libre (F001-950) | ✔ aceptado. **Reenviarlo da 422**, así que hay protección contra duplicados (D3 validada). |
| PUT sobre un documento **rechazado por SUNAT** | ✔ F001-962 (AF-37, rechazada con el error 2640) se corrigió con `PUT /facturas/{id}` (ejemplo 04.30). Se reenvió automáticamente y fue **aceptada con el mismo número**. |

## 3. Estructuras reales (para los DTOs)

**Envoltorio general:** `{estado: "exito"|"error", mensaje, datos?, errores?}`. Tiene **excepciones**:

| Endpoint | Forma real |
|---|---|
| Listados | `datos: { datos: [...], paginacion: {pagina_actual, ultima_pagina, por_pagina, total} }` (no `meta`) |
| Comprobante | `{id, tipo_documento, serie, correlativo, numero_completo, cod_local, fecha_emision "AAAA-MM-DD hh:mm:ss", fecha_vencimiento, tipo_operacion, tipo_moneda, forma_pago, cliente{}, totales{gravadas, igv, total_impuestos, valor_venta, sub_total, total,…}, items[{codigo, descripcion, unidad, cantidad, precio_unitario, valor_unitario, igv, total}], sunat{estado, codigo, descripcion, notas[], hash_cpe}, archivos{xml, cdr, pdf}, leyenda, observacion, estado_pago, monto_pagado, pagos[], enviado_en, creado_en}` |
| Pagos (`POST/GET /{tipo}/{id}/pagos`) | **Sin `estado` ni `datos`**: `{mensaje, estado_pago, monto_pagado, pagos:[{id, metodo, monto, referencia, monto_recibido, vuelto, notas, creado_en}]}`. En la lista también trae `total_documento`. |
| Resumen diario | `datos: {id_resumen, identifier "RC-AAAAMMDD-NNN", fecha_envio, fecha_documentos, correlativo, accion envio|anulacion, total_documentos, estado_sunat, documentos[{id, serie_nro, estado "1"|"3", montos…, estado_sunat}], consulta_estado}` |
| Comunicación de baja | `datos: {id_anulacion, identifier "RA-AAAAMMDD-NNN", correlativo, fecha_comunicacion, total_documentos, estado_sunat, consulta_estado}` |
| `/guias-remision/{id}/estado` | `{estado, mensaje, guia:{id, numero_completo, estado_sunat, codigo_sunat, descripcion_sunat, tiene_ticket}, siguiente_accion}` (HTTP 409 sin ticket) |
| Emisión masiva | HTTP 422 con `estado:"exito"` y `datos:{resumen{total_enviadas, creadas, errores}, facturas[], errores[{indice, mensaje}]}` |

**Estados observados:** `pendiente`, `enviado`, `aceptado`, `rechazado`. Las guías también devuelven `error` (BUILD_ERROR).

## 4. Errores reales

| Caso | HTTP | Cuerpo |
|---|---|---|
| Sin credenciales | 401 | `"Las cabeceras X-Api-Key y X-Api-Secret son requeridas."` |
| Credenciales inválidas | 401 | `"Credenciales de API inválidas."` |
| Validación | 422 | `errores: {"items.0.tip_afe_igv": ["…Catálogo SUNAT N° 07. Códigos válidos: 10…40."]}` |
| Factura con DNI | 422 | `"Las facturas requieren cliente con RUC (tipo_doc=6). Para clientes con DNI… use POST /boletas."` |
| Correlativo duplicado | 422 | `"El correlativo N ya existe para la serie F001…"` |
| NC mayor que el documento | 422 | `"El importe total de la nota de crédito (155) no puede superar el del documento… (ERR-3286/3503)"` |
| GRR motivo 04 | 422 | `"Para el motivo 04 el destinatario debe ser el mismo emisor (RUC …)."` |
| PUT sobre aceptado | 422 | `codigo_error: "documento_aceptado_no_editable"`, `siguiente_accion: {operacion: "emitir_nota_credito", …}` |
| Enviar un aceptado | 422 | `"Esta factura ya fue aceptada por SUNAT."` |
| RC duplicado | 422 | `"Las boletas de la fecha … ya están incluidas en un resumen…"` (evita el error SUNAT 2282) |
| ID inexistente | 404 | `"Recurso no encontrado."` |
| Límite de frecuencia | 429 | `"Demasiadas solicitudes. Intenta de nuevo más tarde."` |

## 5. Hallazgos que cambian el diseño

- **H-1 (defecto de la API, diagnóstico corregido el 2026-11-06):** si el payload de `POST /anulaciones` **omite `fecha_comunicacion`**, la API la completa con la **fecha UTC**. Después de las 19:00 de Lima eso ya es el día siguiente, y SUNAT rechaza con **2301** ("IssueDate no debe ser mayor a la fecha de recepción").
  - **Evidencia:** RA-03 y RA-04 (sin `fecha_comunicacion`, igual que sus ejemplos de Postman) fueron rechazadas; las capturas muestran `"fecha_comunicacion": "2026-11-06"` aunque se envió `fecha_generacion` 2026-11-05. RA-01 y RA-02 incluían `fecha_comunicacion` explícita, se enviaron a la misma hora (≈21:05 de Lima) y fueron **aceptadas**.
  - **Mitigación en el sistema:** `BajaService` envía **siempre** `fecha_comunicacion` con la fecha de Lima. No hace falta restringir el horario.
  - **Corrección de fondo (en la API):** el valor por defecto debe usar la zona horaria America/Lima.
- **H-2 (límite de frecuencia):** `X-RateLimit-Limit: 30` por minuto por cuenta. El `SunatApiClient` necesita un limitador compartido (Redis) de unas 25 solicitudes por minuto por empresa, con reintento ante 429. Los envíos se hacen siempre por cola, nunca en ráfaga.
- **H-3:** el **correlativo explícito funciona** y la API rechaza duplicados. Se confirma D3: la reserva local con `FOR UPDATE` y el reintento son seguros.
- **H-4:** la API **valida antes de SUNAT**:
  - factura solo para RUC;
  - catálogo 07 completo (acepta los 19 códigos);
  - total de la NC menor o igual al del documento afectado;
  - destinatario de la GRR con motivo 04.

  El sistema replicará estas reglas en sus Form Requests para avisar antes de enviar.
- **H-5:** las boletas `solo_registro` quedan pendientes hasta el RC, y **solo puede haber un RC de envío por fecha**. El job del resumen debe consultar el estado del RC existente en lugar de crear otro.
- **H-6:** la emisión masiva responde **422 con `estado:"exito"`** y **expone SQL interno** (PostgreSQL, nombres de tablas). Hay que corregirlo en la API. En el sistema no se usará `/masivo`: se emitirá una por una mediante la cola.
- **H-7:** las guías (GRE) necesitan las credenciales de la **API GRE de SUNAT** (`client_id` y `client_secret` generados en SOL) configuradas en la empresa de la API. Sin ellas: "Cliente No autorizado".
  - **Actualización 2026-11-06:** la cuenta ya emite guías. T009-6 y T001-20, enviadas desde el sistema, fueron **aceptadas** en beta (código 0, "ACEPTADA"; ticket con prefijo `test-`). `GET /guias-remision/{id}/estado` devuelve la guía completa con `sunat{estado, codigo, descripcion, ticket}`. También se verificó que la API **respeta el correlativo explícito** en guías y rechaza duplicados con 422.
- **H-8:** `consultar-cpe` **no funciona en beta**: usa el servicio real de SUNAT y requiere `client_id` y `client_secret` de la consulta integrada. Además, los parámetros reales difieren de Postman: `monto` (no `monto_total`) y la fecha en formato `dd/mm/aaaa`. `consultar-cdr` exige `tipo_documento` (no `tipo_doc`) y en beta responde `success:false, NETWORK_ERROR`.
- **H-9:** las fechas vienen en formatos mixtos: `fecha_emision` como `"AAAA-MM-DD hh:mm:ss"` en hora local, y `enviado_en` y `creado_en` en ISO-8601 UTC.
- **H-10:** la paginación está en `datos.paginacion` (no en `meta`). Los pagos y algunos estados no usan el envoltorio `datos`.
- **H-13 (defecto de la API, 2026-11-06):** `POST /cotizaciones` y `POST /notas-venta` responden **HTTP 500**. Al generar el número interno (`COT-AAAAMM-…`, `NV-AAAAMM-…`) la API usa `CAST(... AS UNSIGNED)`, que es sintaxis de MySQL, sobre PostgreSQL (`type "unsigned" does not exist`). Igual que en H-6, la respuesta **expone SQL interno**: tabla `internal_documents`, host, base de datos y `tenant_id`. El listado (`GET /cotizaciones`) sí responde. Consecuencias para el sistema:
  - las cotizaciones se gestionan solo en el sistema, sin la API, y se imprimen como página del navegador;
  - las notas de venta no se implementan: además del defecto, toda venta debe sustentarse con un comprobante electrónico.
  - **Corrección en la API:** usar `CAST(... AS INTEGER)` o una secuencia, y no devolver el mensaje SQL al cliente.
- **H-14 (limitación de la API, 2026-11-06):** `GET /resumenes/{id}/xml` y `/cdr` devuelven el XML (`application/xml`) y el CDR (`application/zip`) del RC, tanto el de envío como el de anulación. En cambio, `GET /anulaciones/{id}/cdr` de una comunicación de baja (RA) **aceptada** responde 404 `"CDR de la anulación no disponible"`, aunque `/estado` informa código 0. El XML del RA sí se descarga. En el sistema, el botón CDR de una baja RA muestra ese mensaje de la API.
- **H-15 (limitación de la API, 2026-11-06):** SUNAT exige anular por resumen diario (RC) tanto las boletas como sus notas de crédito y débito (series B). En la API, `POST /resumenes` con `anular` solo acepta **boletas**. Se probó con la NC aceptada BC02-1 (`id` 35 en `/notas-credito`, sin boleta con ese id) y la respuesta fue 422 `"No se encontraron boletas aceptadas por SUNAT para anular."`. Se intentó también con `tipo_documento` y con `tipo`, sin éxito, y en ningún caso se creó ni se envió nada. El sistema rechaza la baja de notas de boletas con un mensaje claro. Las notas no se envían por RA, porque SUNAT no las acepta así. Documentos de prueba de esta validación: boleta B002-1 (S/ 1.00) y NC BC02-1 (motivo 01), ambas aceptadas en beta.
- **H-16 (2026-11-07, retenciones y percepciones):** se validó en beta el ciclo completo. La retención R002-1 (3 %, S/ 7.08) y la percepción P002-1 (2 %, S/ 1.00) fueron aceptadas, y la reversión RR-20261107-001 de R002-1 también: después la API marca la retención como `anulado`. Hallazgos: (a) `GET /percepciones/{id}/pdf` responde 404 "Recurso no encontrado" con `format` y con `formato`; las percepciones solo tienen XML y CDR. (b) `POST /reversiones` devuelve el id como `voided_id` y sugiere consultar `consulta_estado: /voided/{id}/status`, pero esa URL responde 404; la que funciona es `GET /anulaciones/{id}/estado`. (c) El detalle de retenciones y percepciones devuelve `sunat_code` sin descripción. Series propias del sistema: R002 y P002, porque R001 y P001 las usa otro sistema con la misma cuenta.
- **H-17 (2026-11-08, liquidación de compra):** ciclo validado en beta: L002-1 y L002-2 aceptadas, pago registrado y eliminado, y L002-2 revertida con RR-20261108-001 (la API la marca `anulado`). Hallazgos: (a) la renta sale siempre 0 %, incluso con `productos_agropecuarios: true`, y SUNAT agrega la observación 4237 ("La tasa del tributo de la línea no corresponde", valor 0); la API ignora `porcentaje_renta` y el documento se acepta igual. Hay que confirmar con quien mantiene la API cómo se aplica la retención de renta. (b) La serie usa el tipo `liquidacion_compra`. (c) Valores aceptados: `vendedor.tipo_doc` 1 y 4, `tip_afe_igv` 10, 20 y 30, `tipo_operacion` 0501 y 0503. (d) El endpoint no está en la especificación OpenAPI, solo en Postman.

## 6. Afectaciones del IGV: matriz real (fase G y ejemplos de Postman)

Los códigos sin ejemplo en Postman se probaron con payload propio, usando la forma del ejemplo "Factura gratuita": `precio_unitario 0` + `mto_valor_unitario`, y `porcentaje_igv 18` en los gravados.

| Código | Resultado SUNAT beta | Evidencia |
|---|---|---|
| 10, 11, 13, 17, 20, 30, 32, 40 | ✔ aceptado | ejemplos de Postman (F-01 a F-24) |
| 12, 14, 15, 16 (gravados gratuitos) | ✔ aceptado | AF-12, AF-14, AF-15, AF-16 (F001-952 a 955) |
| 21 (exonerado, transferencia gratuita) | ✔ aceptado | AF-21 (F001-956) |
| 31, 33, 34, 35, 36 (inafectos gratuitos) | ✔ aceptado | AF-31 a AF-36 (F001-957 a 961) |
| **37 (inafecto, transferencia gratuita)** | ✘ **rechazado 2640** | AF-37 (F001-962). **Defecto de la API (H-11).** |

**H-11 (defecto de la API):** para el código 37 la API arma el XML como una operación onerosa. Omite el tributo `9996 GRA`, usa `PriceTypeCode 01` con 0 en lugar de `02` con el valor referencial, y pone 100 en `cac:Price`. SUNAT lo rechaza: `2640 (nodo cac:Price/cbc:PriceAmount valor 100)`. Comparando con el XML del código 36, que sí fue aceptado (`output/files/AF-36.xml` frente a `AF-37.xml`), se ve que solo falta incluir el 37 en la lógica de gratuitas de la API. Mientras no se corrija, el sistema deshabilita el código 37 (`validado_api = false`).

## 7. Pendientes de la Etapa 1

| Pendiente | Requiere |
|---|---|
| B-04 (NRUS) y B-05 (tasa reducida) | Autorización para cambiar temporalmente el régimen de la empresa en la API (`--regimen`); se restaura al terminar. |
| Guías GRR-01 a GRR-06 | Configurar las credenciales GRE de SUNAT en la empresa de la API. |
| Opcionales (GRT, retención, percepción, RR, liquidación de compra) | Decidir si entran al alcance (D4). |
