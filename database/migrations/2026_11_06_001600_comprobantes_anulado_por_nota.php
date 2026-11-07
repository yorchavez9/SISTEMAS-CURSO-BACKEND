<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factura/boleta anulada mediante una nota de crédito total (motivos 01, 02 y 06) aceptada por SUNAT.
 * El estado SUNAT del comprobante sigue "aceptado" (los reportes lo cuentan y la NC lo resta, como en el
 * registro de ventas); este campo hace que el sistema lo muestre como "Anulado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->foreignId('anulado_por_nota_id')->nullable()->after('comprobante_referencia_id')->constrained('comprobantes')->nullOnDelete();
        });

        // Notas de crédito totales ya aceptadas antes de este cambio.
        $notas = DB::table('comprobantes')->where('tipo_documento', '07')->whereIn('cod_motivo', ['01', '02', '06'])
            ->whereIn('estado', ['aceptado', 'observado'])->whereNotNull('comprobante_referencia_id')->orderBy('id')->get(['id', 'comprobante_referencia_id']);
        foreach ($notas as $n) {
            DB::table('comprobantes')->where('id', $n->comprobante_referencia_id)->whereNull('anulado_por_nota_id')->update(['anulado_por_nota_id' => $n->id]);
        }
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('anulado_por_nota_id');
        });
    }
};
