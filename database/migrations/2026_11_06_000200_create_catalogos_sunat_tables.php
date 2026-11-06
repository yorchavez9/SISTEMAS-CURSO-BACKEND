<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogos SUNAT genéricos (01, 02, 03, 06, 08, 09, 10, 12, 20, 22, 51, 53, 54, 59…).
        Schema::create('sunat_catalogos', function (Blueprint $table) {
            $table->id();
            $table->string('catalogo', 4);
            $table->string('codigo', 10);
            $table->string('descripcion', 255);
            $table->boolean('activo')->default(true);
            // validado_api: probado contra la API/SUNAT beta (Etapa 1); false = disponible solo tras validación.
            $table->boolean('validado_api')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['catalogo', 'codigo']);
            $table->index(['catalogo', 'activo']);
        });

        // Catálogo 07 con las reglas de cálculo que usa el motor tributario.
        Schema::create('tipos_afectacion_igv', function (Blueprint $table) {
            $table->char('codigo', 2)->primary();
            $table->string('descripcion', 120);
            $table->enum('grupo', ['gravado', 'exonerado', 'inafecto', 'exportacion', 'ivap']);
            $table->boolean('es_gratuito')->default(false);
            $table->char('tributo_codigo', 4);   // catálogo 05: 1000 IGV, 1016 IVAP, 9995 EXP, 9996 GRA, 9997 EXO, 9998 INA
            $table->string('tributo_nombre', 10);
            $table->char('tributo_tipo', 3);     // VAT, FRE
            $table->boolean('calcula_igv')->default(false); // el impuesto se calcula (aunque no se cobre en gratuitas)
            $table->boolean('validado_api')->default(false);
            $table->string('evidencia', 255)->nullable();   // comprobante beta que lo valida
            $table->string('observacion', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_afectacion_igv');
        Schema::dropIfExists('sunat_catalogos');
    }
};
