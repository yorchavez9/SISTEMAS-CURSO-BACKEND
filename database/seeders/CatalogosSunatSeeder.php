<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos SUNAT. "validado_api" refleja la evidencia REAL de la Etapa 1
 * (docs/etapa1-resultados-api.md): true solo si un comprobante con ese valor fue aceptado en beta.
 * Idempotente (upsert).
 */
class CatalogosSunatSeeder extends Seeder
{
    /** Catálogo 07: [codigo, descripcion, grupo, gratuito, tributo, nombre, tipo, calcula_igv, validado, evidencia, observacion] */
    private const AFECTACIONES = [
        ['10', 'Gravado - Operación onerosa', 'gravado', false, '1000', 'IGV', 'VAT', true, true, 'F001-911 (F-02) y otros', null],
        ['11', 'Gravado - Retiro por premio', 'gravado', true, '9996', 'GRA', 'FRE', true, true, 'F001-916 (F-07)', null],
        ['12', 'Gravado - Retiro por donación', 'gravado', true, '9996', 'GRA', 'FRE', true, true, 'F001-952 (AF-12)', null],
        ['13', 'Gravado - Retiro', 'gravado', true, '9996', 'GRA', 'FRE', true, true, 'F001-926 (F-17), F001-927 (F-18)', null],
        ['14', 'Gravado - Retiro por publicidad', 'gravado', true, '9996', 'GRA', 'FRE', true, true, 'F001-953 (AF-14)', null],
        ['15', 'Gravado - Bonificaciones', 'gravado', true, '9996', 'GRA', 'FRE', true, true, 'F001-954 (AF-15)', null],
        ['16', 'Gravado - Retiro por entrega a trabajadores', 'gravado', true, '9996', 'GRA', 'FRE', true, true, 'F001-955 (AF-16)', null],
        ['17', 'Gravado - IVAP', 'ivap', false, '1016', 'IVAP', 'VAT', true, true, 'F001-924 (F-15)', null],
        ['20', 'Exonerado - Operación onerosa', 'exonerado', false, '9997', 'EXO', 'VAT', false, true, 'F001-913 (F-04)', null],
        ['21', 'Exonerado - Transferencia gratuita', 'exonerado', true, '9996', 'GRA', 'FRE', false, true, 'F001-956 (AF-21)', null],
        ['30', 'Inafecto - Operación onerosa', 'inafecto', false, '9998', 'INA', 'FRE', false, true, 'F001-915 (F-06)', null],
        ['31', 'Inafecto - Retiro por bonificación', 'inafecto', true, '9996', 'GRA', 'FRE', false, true, 'F001-957 (AF-31)', null],
        ['32', 'Inafecto - Retiro', 'inafecto', true, '9996', 'GRA', 'FRE', false, true, 'F001-926 (F-17)', null],
        ['33', 'Inafecto - Retiro por muestras médicas', 'inafecto', true, '9996', 'GRA', 'FRE', false, true, 'F001-958 (AF-33)', null],
        ['34', 'Inafecto - Retiro por convenio colectivo', 'inafecto', true, '9996', 'GRA', 'FRE', false, true, 'F001-959 (AF-34)', null],
        ['35', 'Inafecto - Retiro por premio', 'inafecto', true, '9996', 'GRA', 'FRE', false, true, 'F001-960 (AF-35)', null],
        ['36', 'Inafecto - Retiro por publicidad', 'inafecto', true, '9996', 'GRA', 'FRE', false, true, 'F001-961 (AF-36)', null],
        ['37', 'Inafecto - Transferencia gratuita', 'inafecto', true, '9996', 'GRA', 'FRE', false, false, 'F001-962 (AF-37) rechazado 2640',
            'Defecto de la API (H-11): arma el XML como operación onerosa (omite 9996 GRA, PriceTypeCode 01). Deshabilitado hasta su corrección.'],
        ['40', 'Exportación de bienes o servicios', 'exportacion', false, '9995', 'EXP', 'FRE', false, true, 'F001-914 (F-05)', null],
    ];

    /** [catalogo => [[codigo, descripcion, validado_api, meta?]]] */
    private function catalogos(): array
    {
        return [
            // 01 Tipo de documento
            '01' => [
                ['01', 'Factura', true], ['03', 'Boleta de venta', true], ['07', 'Nota de crédito', true], ['08', 'Nota de débito', true],
                ['09', 'Guía de remisión remitente', false, ['nota' => 'Requiere credenciales GRE SUNAT en la cuenta API (H-7)']],
                ['31', 'Guía de remisión transportista', false], ['20', 'Comprobante de retención', false],
                ['40', 'Comprobante de percepción', false], ['04', 'Liquidación de compra', false],
            ],
            // 02 Monedas usadas por la API
            '02' => [['PEN', 'Soles', true, ['simbolo' => 'S/']], ['USD', 'Dólares americanos', true, ['simbolo' => 'US$']]],
            // 03 Unidades de medida (subconjunto de uso común; ampliable)
            '03' => [
                ['NIU', 'Unidad (bienes)', true], ['ZZ', 'Unidad (servicios)', true], ['KGM', 'Kilogramo', true], ['GLL', 'Galón', true],
                ['SA', 'Saco', true], ['LTR', 'Litro', false], ['MTR', 'Metro', false], ['BX', 'Caja', false], ['DZN', 'Docena', false],
                ['PK', 'Paquete', false], ['GRM', 'Gramo', false], ['TNE', 'Tonelada', false], ['MTK', 'Metro cuadrado', false],
                ['MTQ', 'Metro cúbico', false], ['HUR', 'Hora', false], ['DAY', 'Día', false], ['MON', 'Mes', false], ['PR', 'Par', false],
            ],
            // 06 Documento de identidad
            '06' => [
                ['0', 'Otros / sin documento', true, ['nota' => 'Boletas < S/ 700 y exportación']], ['1', 'DNI', true, ['longitud' => 8]],
                ['4', 'Carné de extranjería', false, ['longitud_max' => 12]], ['6', 'RUC', true, ['longitud' => 11]],
                ['7', 'Pasaporte', false, ['longitud_max' => 12]], ['A', 'Cédula diplomática de identidad', false],
            ],
            // 08 Sistema de cálculo del ISC
            '08' => [['01', 'Sistema al valor', true], ['02', 'Aplicación del monto fijo', false], ['03', 'Sistema de precios de venta al público', false]],
            // 09 Motivos de nota de crédito
            '09' => [
                ['01', 'Anulación de la operación', true], ['02', 'Anulación por error en el RUC', false],
                ['03', 'Corrección por error en la descripción', false], ['04', 'Descuento global', true], ['05', 'Descuento por ítem', false],
                ['06', 'Devolución total', true], ['07', 'Devolución por ítem', false], ['08', 'Bonificación', false],
                ['09', 'Disminución en el valor', false], ['10', 'Otros conceptos', false],
                ['11', 'Ajustes de operaciones de exportación', false], ['12', 'Ajustes afectos al IVAP', false],
                ['13', 'Corrección del monto neto pendiente de pago y/o fechas de vencimiento', false],
            ],
            // 10 Motivos de nota de débito
            '10' => [
                ['01', 'Intereses por mora', true], ['02', 'Aumento en el valor', true], ['03', 'Penalidades / otros conceptos', false],
                ['11', 'Ajustes de operaciones de exportación', false], ['12', 'Ajustes afectos al IVAP', false],
            ],
            // 12 Documentos relacionados (anticipos)
            '12' => [['02', 'Factura emitida por anticipos', true], ['03', 'Boleta de venta emitida por anticipos', true]],
            // 20 Motivos de traslado (guías)
            '20' => [
                ['01', 'Venta', false], ['02', 'Compra', false], ['04', 'Traslado entre establecimientos de la misma empresa', false],
                ['08', 'Importación', false], ['09', 'Exportación', false], ['13', 'Otros', false],
            ],
            // 22 Régimen de percepción
            '22' => [
                ['01', 'Percepción venta interna', true, ['tasa' => 2]], ['02', 'Percepción a la adquisición de combustible', true, ['tasa' => 1]],
                ['03', 'Percepción realizada al agente de percepción con tasa especial', true, ['tasa' => 0.5]],
            ],
            // 51 Tipo de operación
            '51' => [
                ['0101', 'Venta interna', true], ['0200', 'Exportación de bienes', true],
                ['0113', 'Venta interna - NRUS', false, ['nota' => 'La API lo asigna automáticamente si la empresa es NRUS']],
                ['1001', 'Operación sujeta a detracción', true], ['2001', 'Operación sujeta a percepción', true],
            ],
            // 53 Descuentos (los cargos no tienen ejemplo en Postman y no se habilitan)
            '53' => [
                ['00', 'Descuento que afecta la base imponible (por ítem)', true], ['02', 'Descuento global que afecta la base imponible', true],
                ['03', 'Descuento global que no afecta la base imponible', true],
                ['04', 'Descuento global por anticipos gravados', true, ['nota' => 'Lo agrega la API automáticamente en anticipos']],
            ],
            // 54 Bienes y servicios sujetos a detracción (subconjunto; verificar tasas vigentes)
            '54' => [
                ['012', 'Intermediación laboral y tercerización', false], ['014', 'Carnes y despojos comestibles', true, ['nota' => 'Usado en el ejemplo Postman de detracción']],
                ['019', 'Arrendamiento de bienes muebles', false], ['020', 'Mantenimiento y reparación de bienes muebles', false],
                ['021', 'Movimiento de carga', false], ['022', 'Otros servicios empresariales', false], ['024', 'Comisión mercantil', false],
                ['025', 'Fabricación de bienes por encargo', false], ['026', 'Servicio de transporte de personas', false],
                ['027', 'Servicio de transporte de carga', false], ['030', 'Contratos de construcción', false],
                ['037', 'Demás servicios gravados con el IGV', false],
            ],
            // 59 Medios de pago (detracción)
            '59' => [
                ['001', 'Depósito en cuenta', true], ['002', 'Giro', false], ['003', 'Transferencia de fondos', false],
                ['004', 'Orden de pago', false], ['005', 'Tarjeta de débito', false], ['006', 'Tarjeta de crédito emitida en el país', false],
                ['009', 'Efectivo, en los demás casos', false],
            ],
        ];
    }

    public function run(): void
    {
        $now = now();
        DB::table('tipos_afectacion_igv')->upsert(array_map(fn ($a) => [
            'codigo' => $a[0], 'descripcion' => $a[1], 'grupo' => $a[2], 'es_gratuito' => $a[3], 'tributo_codigo' => $a[4],
            'tributo_nombre' => $a[5], 'tributo_tipo' => $a[6], 'calcula_igv' => $a[7], 'validado_api' => $a[8],
            'evidencia' => $a[9], 'observacion' => $a[10], 'activo' => $a[8], 'created_at' => $now, 'updated_at' => $now,
        ], self::AFECTACIONES), ['codigo'], ['descripcion', 'grupo', 'es_gratuito', 'tributo_codigo', 'tributo_nombre', 'tributo_tipo', 'calcula_igv', 'validado_api', 'evidencia', 'observacion', 'activo', 'updated_at']);

        $rows = [];
        foreach ($this->catalogos() as $cat => $items) {
            foreach ($items as $it) {
                if ($cat === '54') {
                    // Descripciones y porcentajes de detracción cambian por resolución: verificar con el anexo vigente.
                    $it[3] = ($it[3] ?? []) + ['verificar_con_anexo_vigente' => true];
                }
                $rows[] = [
                    'catalogo' => $cat, 'codigo' => $it[0], 'descripcion' => $it[1], 'validado_api' => $it[2],
                    'meta' => isset($it[3]) ? json_encode($it[3], JSON_UNESCAPED_UNICODE) : null,
                    'activo' => true, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::table('sunat_catalogos')->upsert($rows, ['catalogo', 'codigo'], ['descripcion', 'validado_api', 'meta', 'updated_at']);
    }
}
