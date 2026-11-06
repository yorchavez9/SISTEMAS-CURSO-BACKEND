<?php

namespace App\Modules\Reportes\Services;

use App\Models\Empresa;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Registro de ventas en .xlsx a partir de los MISMOS datos del reporte en pantalla
 * (ReporteController::datosRegistro): las notas de crédito restan y los anulados/rechazados figuran en cero.
 */
class RegistroVentasExcel
{
    private const TIPOS = ['01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito', '08' => 'Nota de débito'];

    private const ESTADOS = [
        'por_enviar' => 'Por enviar', 'pendiente_resumen' => 'Pendiente de resumen', 'enviado' => 'En SUNAT', 'aceptado' => 'Aceptado',
        'observado' => 'Observado', 'rechazado' => 'Rechazado', 'error' => 'Error de envío', 'anulado' => 'Anulado',
    ];

    /** Columnas de importe, en orden. */
    private const IMPORTES = [
        'gravadas' => 'Op. gravadas', 'exoneradas' => 'Op. exoneradas', 'inafectas' => 'Op. inafectas', 'exportacion' => 'Exportación',
        'gratuitas' => 'Op. gratuitas', 'igv' => 'IGV', 'isc' => 'ISC', 'icbper' => 'ICBPER', 'total' => 'Importe total',
    ];

    private const FORMATO_MONTO = '#,##0.00;-#,##0.00;"-"';

    public function generar(Empresa $empresa, string $desde, string $hasta, string $moneda, array $datos): string
    {
        $libro = new Spreadsheet;
        $libro->getProperties()->setCreator('Sistema de ventas')->setTitle("Registro de ventas {$empresa->ruc}")
            ->setDescription("Del $desde al $hasta en $moneda");
        $libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $this->hojaRegistro($libro->getActiveSheet(), $empresa, $desde, $hasta, $moneda, $datos['filas']->all());
        $this->hojaResumen($libro->createSheet(), $empresa, $desde, $hasta, $moneda, $datos['resumen']->all());
        $libro->setActiveSheetIndex(0);

        $f = fopen('php://memory', 'w+');
        (new Xlsx($libro))->save($f);
        rewind($f);
        $contenido = stream_get_contents($f);
        fclose($f);
        $libro->disconnectWorksheets();

        return $contenido;
    }

    private function encabezado(Worksheet $h, Empresa $empresa, string $titulo, string $desde, string $hasta, string $moneda, int $columnas): void
    {
        $ultima = Coordinate::stringFromColumnIndex($columnas);
        $h->setCellValue('A1', $titulo)->mergeCells("A1:{$ultima}1");
        $h->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $h->setCellValue('A2', "{$empresa->razon_social} · RUC {$empresa->ruc}")->mergeCells("A2:{$ultima}2");
        $periodo = Carbon::parse($desde)->format('d/m/Y').' al '.Carbon::parse($hasta)->format('d/m/Y');
        $h->setCellValue('A3', "Periodo: $periodo · Moneda: ".($moneda === 'PEN' ? 'soles (PEN)' : 'dólares (USD)')
            .' · Generado: '.Carbon::now('America/Lima')->format('d/m/Y H:i'))->mergeCells("A3:{$ultima}3");
        $h->getStyle('A2:A3')->getFont()->getColor()->setRGB('555555');
    }

    private function cabeceraTabla(Worksheet $h, int $fila, array $titulos): void
    {
        foreach (array_values($titulos) as $i => $t) {
            $h->setCellValue([$i + 1, $fila], $t);
        }
        $rango = 'A'.$fila.':'.Coordinate::stringFromColumnIndex(count($titulos)).$fila;
        $estilo = $h->getStyle($rango);
        $estilo->getFont()->setBold(true);
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF5');
        $estilo->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $estilo->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function hojaRegistro(Worksheet $h, Empresa $empresa, string $desde, string $hasta, string $moneda, array $filas): void
    {
        $h->setTitle('Registro');
        $fijas = ['Fecha emisión', 'Tipo', 'Comprobante', 'Doc. que modifica', 'Tipo doc. cliente', 'N.° doc. cliente', 'Cliente', 'Estado'];
        $titulos = array_merge($fijas, array_values(self::IMPORTES));
        $this->encabezado($h, $empresa, 'REGISTRO DE VENTAS E INGRESOS', $desde, $hasta, $moneda, count($titulos));
        $cab = 5;
        $this->cabeceraTabla($h, $cab, $titulos);

        $r = $cab + 1;
        foreach ($filas as $f) {
            $h->setCellValue([1, $r], ExcelDate::PHPToExcel(Carbon::parse($f['fecha'])->startOfDay()));
            $h->setCellValueExplicit([2, $r], $f['tipo'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $h->setCellValue([3, $r], $f['numero']);
            $h->setCellValue([4, $r], $f['referencia'] ?? '');
            $h->setCellValueExplicit([5, $r], (string) $f['cliente_tipo_doc'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // Texto: conserva ceros a la izquierda de DNI/RUC.
            $h->setCellValueExplicit([6, $r], (string) $f['cliente_doc'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $h->setCellValue([7, $r], $f['cliente']);
            $h->setCellValue([8, $r], self::ESTADOS[$f['estado']] ?? $f['estado']);
            $c = count($fijas) + 1;
            foreach (array_keys(self::IMPORTES) as $k) {
                $h->setCellValue([$c++, $r], (float) $f[$k]);
            }
            if (! in_array($f['estado'], MetricasService::ESTADOS_VALIDOS, true)) {
                $h->getStyle("A$r:".Coordinate::stringFromColumnIndex(count($titulos)).$r)->getFont()->getColor()->setRGB('888888');
            }
            $r++;
        }
        $ultimaDatos = $r - 1;

        // Fila de totales con fórmulas: el archivo sigue cuadrando si el usuario filtra o revisa.
        $h->setCellValue([1, $r], 'Totales');
        $h->mergeCells("A$r:".Coordinate::stringFromColumnIndex(count($fijas)).$r);
        for ($c = count($fijas) + 1; $c <= count($titulos); $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $h->setCellValue([$c, $r], $ultimaDatos > $cab ? '=SUBTOTAL(9,'.$col.($cab + 1).':'.$col.$ultimaDatos.')' : 0);
        }
        $tot = $h->getStyle("A$r:".Coordinate::stringFromColumnIndex(count($titulos)).$r);
        $tot->getFont()->setBold(true);
        $tot->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

        $h->getStyle('A'.($cab + 1).":A$ultimaDatos")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $h->getStyle(Coordinate::stringFromColumnIndex(count($fijas) + 1).($cab + 1).':'.Coordinate::stringFromColumnIndex(count($titulos)).$r)
            ->getNumberFormat()->setFormatCode(self::FORMATO_MONTO);
        $anchos = [12, 6, 14, 14, 9, 14, 40, 16];
        foreach ($anchos as $i => $w) {
            $h->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
        for ($c = count($fijas) + 1; $c <= count($titulos); $c++) {
            $h->getColumnDimensionByColumn($c)->setWidth(13);
        }
        $h->getRowDimension($cab)->setRowHeight(28);
        $h->freezePane('A'.($cab + 1));
        if ($ultimaDatos > $cab) {
            $h->setAutoFilter("A$cab:".Coordinate::stringFromColumnIndex(count($titulos)).$ultimaDatos);
        }
        $h->setCellValue('A'.($r + 2), 'Las notas de crédito figuran con signo negativo. Los comprobantes anulados, rechazados o con error figuran con importes en cero (en gris). Tipo: 01 factura, 03 boleta, 07 nota de crédito, 08 nota de débito.');
        $h->getStyle('A'.($r + 2))->getFont()->setItalic(true)->getColor()->setRGB('555555');
        $h->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    }

    private function hojaResumen(Worksheet $h, Empresa $empresa, string $desde, string $hasta, string $moneda, array $resumen): void
    {
        $h->setTitle('Resumen');
        $titulos = array_merge(['Tipo de comprobante', 'Cantidad'], array_values(self::IMPORTES));
        $this->encabezado($h, $empresa, 'RESUMEN POR TIPO DE COMPROBANTE', $desde, $hasta, $moneda, count($titulos));
        $cab = 5;
        $this->cabeceraTabla($h, $cab, $titulos);
        $r = $cab + 1;
        foreach ($resumen as $fila) {
            $h->setCellValue([1, $r], (self::TIPOS[$fila['tipo']] ?? $fila['tipo'])." ({$fila['tipo']})");
            $h->setCellValue([2, $r], (int) $fila['cantidad']);
            $c = 3;
            foreach (array_keys(self::IMPORTES) as $k) {
                $h->setCellValue([$c++, $r], (float) ($fila[$k] ?? 0));
            }
            $r++;
        }
        $h->setCellValue([1, $r], 'Total');
        for ($c = 2; $c <= count($titulos); $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            $h->setCellValue([$c, $r], $r > $cab + 1 ? "=SUM({$col}".($cab + 1).":{$col}".($r - 1).')' : 0);
        }
        $h->getStyle("A$r:".Coordinate::stringFromColumnIndex(count($titulos)).$r)->getFont()->setBold(true);
        $h->getStyle("C".($cab + 1).':'.Coordinate::stringFromColumnIndex(count($titulos)).$r)->getNumberFormat()->setFormatCode(self::FORMATO_MONTO);
        $h->getColumnDimension('A')->setWidth(26);
        $h->getColumnDimension('B')->setWidth(10);
        for ($c = 3; $c <= count($titulos); $c++) {
            $h->getColumnDimensionByColumn($c)->setWidth(13);
        }
        $h->setCellValue('A'.($r + 2), 'Solo comprobantes válidos (sin anulados ni rechazados). Las notas de crédito restan.');
        $h->getStyle('A'.($r + 2))->getFont()->setItalic(true)->getColor()->setRGB('555555');
    }
}
