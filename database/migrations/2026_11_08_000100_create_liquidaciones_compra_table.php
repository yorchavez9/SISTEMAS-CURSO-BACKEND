<?php

use Database\Seeders\PermisosSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Liquidaciones de compra (tipo 04): compras a personas sin RUC. Los pagos al vendedor viven en la API. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_compra', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->nullable()->constrained('establecimientos')->nullOnDelete();
            $t->foreignId('serie_id')->nullable()->constrained('series')->nullOnDelete();
            $t->string('serie', 4);
            $t->unsignedInteger('correlativo');
            $t->date('fecha_emision');
            $t->boolean('productos_agropecuarios')->default(false);
            $t->char('vendedor_tipo_doc', 1);
            $t->string('vendedor_num_doc', 15);
            $t->string('vendedor_nombre', 500);
            $t->string('vendedor_direccion', 500);
            $t->char('vendedor_ubigeo', 6);
            $t->string('lugar_direccion', 500);
            $t->char('lugar_ubigeo', 6);
            $t->json('items');
            $t->char('moneda', 3)->default('PEN');
            $t->decimal('valor_venta', 14, 2);
            $t->decimal('igv', 14, 2);
            $t->decimal('porcentaje_renta', 6, 3)->default(0);
            $t->decimal('renta', 14, 2)->default(0);
            $t->decimal('neto_a_pagar', 14, 2);
            $t->decimal('monto_pagado', 14, 2)->default(0);
            $t->string('estado_pago', 20)->default('pendiente');
            $t->string('observacion', 500)->nullable();
            $t->string('estado', 20)->default('por_enviar'); // por_enviar, enviado, aceptado, rechazado, error, revertido
            $t->unsignedBigInteger('api_id')->nullable();
            $t->string('sunat_codigo', 10)->nullable();
            $t->string('sunat_descripcion', 1000)->nullable();
            $t->json('sunat_notas')->nullable();
            $t->string('ultimo_error', 1000)->nullable();
            $t->unsignedSmallInteger('intentos')->default(0);
            $t->json('payload');
            $t->json('historial')->nullable();
            $t->json('archivos')->nullable();
            $t->string('reversion_estado', 20)->nullable();
            $t->string('reversion_causal', 30)->nullable();
            $t->string('reversion_motivo', 255)->nullable();
            $t->unsignedBigInteger('reversion_api_id')->nullable();
            $t->string('reversion_identifier', 30)->nullable();
            $t->string('reversion_error', 1000)->nullable();
            $t->uuid('idempotency_key');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['empresa_id', 'serie', 'correlativo'], 'liq_empresa_numero_unique');
            $t->unique(['empresa_id', 'idempotency_key'], 'liq_empresa_idem_unique');
            $t->index(['empresa_id', 'fecha_emision'], 'liq_empresa_fecha_index');
        });

        (new PermisosSeeder)->run(); // permisos liquidaciones.*
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_compra');
    }
};
