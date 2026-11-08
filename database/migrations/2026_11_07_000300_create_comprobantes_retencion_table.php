<?php

use Database\Seeders\PermisosSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobantes de retención (20) y de percepción (40), con su reversión (RR).
 * Un registro por comprobante; los documentos relacionados (facturas pagadas o cobradas) van en JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_retencion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->nullable()->constrained('establecimientos')->nullOnDelete();
            $t->foreignId('serie_id')->nullable()->constrained('series')->nullOnDelete();
            $t->char('tipo_documento', 2); // 20 retención, 40 percepción
            $t->string('serie', 4);
            $t->unsignedInteger('correlativo');
            $t->date('fecha_emision');
            $t->char('regimen', 2);
            $t->decimal('tasa', 6, 3);
            // Proveedor (retención) o cliente (percepción)
            $t->char('tercero_tipo_doc', 1);
            $t->string('tercero_num_doc', 15);
            $t->string('tercero_razon_social', 500);
            $t->string('tercero_direccion', 500)->nullable();
            $t->char('moneda', 3)->default('PEN');
            $t->decimal('importe_documentos', 14, 2); // suma de los comprobantes relacionados
            $t->decimal('importe', 14, 2);            // retenido o percibido
            $t->decimal('importe_neto', 14, 2);       // pagado (total − retención) o cobrado (total + percepción)
            $t->json('documentos');
            $t->string('observacion', 500)->nullable();
            $t->string('estado', 20)->default('por_enviar'); // por_enviar, enviado, aceptado, rechazado, error, revertido
            $t->unsignedBigInteger('api_id')->nullable();
            $t->string('sunat_codigo', 10)->nullable();
            $t->string('sunat_descripcion', 1000)->nullable();
            $t->string('ultimo_error', 1000)->nullable();
            $t->unsignedSmallInteger('intentos')->default(0);
            $t->json('payload');
            $t->json('historial')->nullable();
            $t->json('archivos')->nullable();
            // Reversión (RR)
            $t->string('reversion_estado', 20)->nullable(); // por_enviar, enviado, aceptado, rechazado, error
            $t->string('reversion_motivo', 255)->nullable();
            $t->unsignedBigInteger('reversion_api_id')->nullable();
            $t->string('reversion_identifier', 30)->nullable();
            $t->string('reversion_ticket', 30)->nullable();
            $t->string('reversion_error', 1000)->nullable();
            $t->uuid('idempotency_key');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            // Nombres explícitos: los automáticos superan los 64 caracteres de MySQL.
            $t->unique(['empresa_id', 'serie', 'correlativo'], 'cret_empresa_numero_unique');
            $t->unique(['empresa_id', 'idempotency_key'], 'cret_empresa_idem_unique');
            $t->index(['empresa_id', 'tipo_documento', 'fecha_emision'], 'cret_empresa_tipo_fecha_index');
        });

        (new PermisosSeeder)->run(); // permisos retenciones.*
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_retencion');
    }
};
