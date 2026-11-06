<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Comunicaciones de baja (RA) y resúmenes diarios de anulación (RC). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comunicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->enum('tipo', ['RA', 'RC']);
            $t->date('fecha_documentos');           // fecha de emisión de los comprobantes que comunica
            $t->string('motivo', 100);
            $t->enum('estado', ['por_enviar', 'enviado', 'aceptado', 'rechazado', 'error'])->default('por_enviar');
            $t->unsignedBigInteger('api_id')->nullable();
            $t->string('identifier', 30)->nullable(); // RA-AAAAMMDD-NNN / RC-AAAAMMDD-NNN
            $t->string('ticket', 30)->nullable();
            $t->string('sunat_codigo', 20)->nullable();
            $t->string('sunat_descripcion', 1000)->nullable();
            $t->json('payload');
            $t->unsignedSmallInteger('intentos')->default(0);
            $t->string('ultimo_error', 1000)->nullable();
            $t->boolean('efectos_aplicados')->default(false);
            $t->foreignId('user_id')->constrained('users');
            $t->timestamps();
            $t->index(['empresa_id', 'estado']);
        });

        Schema::create('comunicacion_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('comunicacion_id')->constrained('comunicaciones')->cascadeOnDelete();
            $t->foreignId('comprobante_id')->constrained('comprobantes');
            $t->string('motivo', 100);
            $t->index('comprobante_id');
        });

        Schema::table('ventas', function (Blueprint $t) {
            // true cuando el comprobante de la venta fue dado de baja ante SUNAT (RA/RC): el neto de la venta pasa a 0.
            $t->boolean('anulada_por_baja')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', fn (Blueprint $t) => $t->dropColumn('anulada_por_baja'));
        Schema::dropIfExists('comunicacion_items');
        Schema::dropIfExists('comunicaciones');
    }
};
