<?php

use Database\Seeders\PermisosSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guías de remisión remitente (tipo 09). Estructura del payload: ejemplos Postman GRR-01…GRR-06 (Etapa 1).
 * Correlativo explícito validado en beta (2026-11-06): la API lo respeta y rechaza duplicados con 422.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guias_remision', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->constrained('establecimientos');
            $t->foreignId('serie_id')->constrained('series');
            $t->string('serie', 4);
            $t->unsignedInteger('correlativo');
            $t->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->nullOnDelete(); // comprobante que sustenta el traslado
            $t->date('fecha_emision');
            $t->date('fecha_traslado');
            $t->char('cod_traslado', 2);   // catálogo 20
            $t->char('mod_traslado', 2);   // catálogo 18: 01 público, 02 privado
            $t->decimal('peso_total', 12, 3);
            $t->string('und_peso_total', 3)->default('KGM');
            $t->unsignedInteger('num_bultos')->nullable();
            $t->char('destinatario_tipo_doc', 1);
            $t->string('destinatario_num_doc', 15);
            $t->string('destinatario_razon_social', 250);
            $t->char('partida_ubigeo', 6);
            $t->string('partida_direccion', 250);
            $t->char('llegada_ubigeo', 6);
            $t->string('llegada_direccion', 250);
            $t->enum('estado', ['por_enviar', 'enviado', 'aceptado', 'rechazado', 'error'])->default('por_enviar');
            $t->unsignedBigInteger('api_id')->nullable();
            $t->string('ticket', 60)->nullable();
            $t->string('sunat_codigo', 30)->nullable();
            $t->string('sunat_descripcion', 1000)->nullable();
            $t->json('payload');
            $t->json('archivos')->nullable(); // {xml|cdr|pdf: {path, size, sha256}}
            $t->json('historial')->nullable(); // [{fecha, estado, detalle}]
            $t->unsignedSmallInteger('intentos')->default(0);
            $t->string('ultimo_error', 1000)->nullable();
            $t->string('idempotency_key', 64);
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('enviado_at')->nullable();
            $t->timestamp('aceptado_at')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'serie', 'correlativo']);
            $t->unique(['empresa_id', 'idempotency_key']);
            $t->unique(['empresa_id', 'api_id']);
            $t->index(['empresa_id', 'estado']);
            $t->index(['empresa_id', 'fecha_emision']);
        });

        // Nuevos permisos guias.* en los roles base (idempotente).
        (new PermisosSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('guias_remision');
    }
};
