<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venta (documento comercial) y comprobante electrónico (documento fiscal) son entidades
 * distintas: la venta se registra en una transacción local; el comprobante se envía después
 * por cola y su estado SUNAT evoluciona de forma independiente.
 */
return new class extends Migration
{
    private function montos(Blueprint $t): void
    {
        foreach (['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'base_ivap', 'igv', 'ivap', 'isc', 'icbper', 'descuentos_globales', 'anticipos', 'total_impuestos', 'valor_venta', 'sub_total', 'total'] as $c) {
            $t->decimal($c, 14, 2)->default(0);
        }
    }

    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->constrained('establecimientos');
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->date('fecha');
            $t->char('moneda', 3)->default('PEN');
            $t->enum('forma_pago', ['contado', 'credito'])->default('contado');
            $this->montos($t);
            $t->decimal('tasa_igv', 5, 2);
            $t->string('observacion', 500)->nullable();
            $t->enum('estado', ['registrada', 'anulada'])->default('registrada');
            $t->uuid('idempotency_key');
            $t->timestamps();
            $t->unique(['empresa_id', 'idempotency_key']); // doble clic / reintento del navegador no duplica la venta
            $t->index(['empresa_id', 'fecha']);
        });

        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $t->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $t->string('codigo', 30)->nullable();
            $t->string('descripcion', 255);
            $t->string('unidad', 3);
            $t->decimal('cantidad', 14, 4);
            $t->decimal('precio_unitario', 14, 6);
            $t->char('tip_afe_igv', 2);
            $t->decimal('valor_unitario', 14, 6);
            $t->decimal('valor_referencial', 14, 6)->nullable();
            foreach (['descuento', 'base', 'isc', 'igv', 'icbper', 'total'] as $c) {
                $t->decimal($c, 14, 2)->default(0);
            }
            $t->json('item_api'); // ítem exacto que se envía a la API
        });

        Schema::create('venta_cuotas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $t->unsignedSmallInteger('numero');
            $t->decimal('monto', 14, 2);
            $t->date('fecha_vencimiento');
        });

        Schema::create('comprobantes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->constrained('establecimientos');
            $t->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            $t->foreignId('serie_id')->constrained('series');
            $t->char('tipo_documento', 2);
            $t->char('serie', 4);
            $t->unsignedInteger('correlativo');
            $t->date('fecha_emision');
            $t->date('fecha_vencimiento')->nullable();
            $t->char('tipo_operacion', 4)->default('0101');
            $t->char('moneda', 3)->default('PEN');
            $t->enum('forma_pago', ['contado', 'credito'])->default('contado');
            $t->string('cliente_tipo_doc', 1);
            $t->string('cliente_num_doc', 15);
            $t->string('cliente_razon_social', 255);
            $t->string('cliente_direccion', 255)->nullable();
            $this->montos($t);
            // por_enviar → enviado → aceptado | rechazado | observado; error = no llegó a la API
            $t->enum('estado', ['por_enviar', 'enviado', 'aceptado', 'observado', 'rechazado', 'error', 'anulado'])->default('por_enviar');
            $t->string('sunat_codigo', 20)->nullable();
            $t->string('sunat_descripcion', 1000)->nullable();
            $t->json('sunat_notas')->nullable();
            $t->string('hash_cpe', 100)->nullable();
            $t->unsignedBigInteger('api_id')->nullable();
            $t->json('payload');
            $t->json('totales_api')->nullable();
            $t->boolean('diferencia_totales')->default(false);
            $t->unsignedSmallInteger('intentos')->default(0);
            $t->string('ultimo_error', 1000)->nullable();
            $t->timestamp('enviado_at')->nullable();
            $t->timestamp('aceptado_at')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'tipo_documento', 'serie', 'correlativo']); // nunca dos comprobantes con el mismo número
            $t->unique(['empresa_id', 'api_id']);
            $t->index(['empresa_id', 'estado']);
            $t->index(['empresa_id', 'fecha_emision']);
            $t->index(['empresa_id', 'cliente_num_doc']);
        });

        Schema::create('comprobante_estados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $t->string('estado_anterior', 20)->nullable();
            $t->string('estado_nuevo', 20);
            $t->string('detalle', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('comprobante_archivos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $t->enum('tipo', ['xml', 'cdr', 'pdf']);
            $t->string('path', 255);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->timestamps();
            $t->unique(['comprobante_id', 'tipo']);
        });

        // Trazabilidad de cada llamada a la API (sin credenciales).
        Schema::create('api_solicitudes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $t->string('operacion', 40);
            $t->string('metodo', 7);
            $t->string('endpoint', 255);
            $t->json('payload')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->json('respuesta')->nullable();
            $t->unsignedInteger('duracion_ms')->nullable();
            $t->string('error', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['api_solicitudes', 'comprobante_archivos', 'comprobante_estados', 'comprobantes', 'venta_cuotas', 'venta_detalles', 'ventas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
