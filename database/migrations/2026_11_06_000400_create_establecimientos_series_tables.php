<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establecimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->char('cod_local', 4); // código de establecimiento anexo SUNAT ("0000" = domicilio fiscal)
            $table->string('direccion', 255)->nullable();
            $table->char('ubigeo', 6)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('api_sucursal_id')->nullable();
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'cod_local']);
            $table->unique(['empresa_id', 'api_sucursal_id']);
        });

        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establecimiento_id')->nullable()->constrained('establecimientos')->nullOnDelete();
            $table->char('tipo_documento', 2); // catálogo 01
            $table->char('serie', 4);
            // Último correlativo USADO. El siguiente se reserva con bloqueo de fila (SerieService).
            $table->unsignedInteger('correlativo_actual')->default(0);
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('api_serie_id')->nullable();
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'serie']);
            $table->unique(['empresa_id', 'api_serie_id']);
            $table->index(['empresa_id', 'tipo_documento', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
        Schema::dropIfExists('establecimientos');
    }
};
