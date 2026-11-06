<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envío de boletas por resumen diario (RC de envío):
 *  - empresas.envio_boletas: individual (cada boleta se envía al emitirse) | resumen (solo_registro + RC).
 *  - comprobantes.estado admite "pendiente_resumen": registrada en la API, a la espera del RC.
 *  - comunicaciones.accion: anulacion (RA / RC de baja) | envio (RC de envío de boletas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            $t->enum('envio_boletas', ['individual', 'resumen'])->default('individual')->after('permitir_stock_negativo');
        });
        DB::statement("ALTER TABLE comprobantes MODIFY estado ENUM('por_enviar','pendiente_resumen','enviado','aceptado','observado','rechazado','error','anulado') NOT NULL DEFAULT 'por_enviar'");
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->boolean('envio_por_resumen')->default(false)->after('estado');
        });
        Schema::table('comunicaciones', function (Blueprint $t) {
            $t->enum('accion', ['anulacion', 'envio'])->default('anulacion')->after('tipo');
            $t->unsignedSmallInteger('documentos_externos')->default(0)->after('efectos_aplicados'); // incluidos por la API pero no emitidos por este sistema
        });
    }

    public function down(): void
    {
        Schema::table('comunicaciones', fn (Blueprint $t) => $t->dropColumn(['accion', 'documentos_externos']));
        Schema::table('comprobantes', fn (Blueprint $t) => $t->dropColumn('envio_por_resumen'));
        DB::statement("UPDATE comprobantes SET estado = 'enviado' WHERE estado = 'pendiente_resumen'");
        DB::statement("ALTER TABLE comprobantes MODIFY estado ENUM('por_enviar','enviado','aceptado','observado','rechazado','error','anulado') NOT NULL DEFAULT 'por_enviar'");
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('envio_boletas'));
    }
};
