<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->foreignId('comprobante_referencia_id')->nullable()->after('venta_id')->constrained('comprobantes');
            $t->char('cod_motivo', 2)->nullable()->after('comprobante_referencia_id');
            $t->string('des_motivo', 250)->nullable()->after('cod_motivo');
            $t->uuid('idempotency_key')->nullable()->after('des_motivo'); // notas: el mismo formulario reenviado no duplica la nota
            $t->unique(['empresa_id', 'idempotency_key']);
        });

        // Ítems de las notas (las facturas/boletas usan venta_detalles).
        Schema::create('comprobante_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();
            $t->foreignId('venta_detalle_id')->nullable()->constrained('venta_detalles'); // línea original devuelta (NC por ítem/total)
            $t->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $t->string('descripcion', 255);
            $t->string('unidad', 3);
            $t->decimal('cantidad', 14, 4);
            $t->decimal('precio_unitario', 14, 6);
            $t->char('tip_afe_igv', 2);
            foreach (['base', 'igv', 'isc', 'icbper', 'total'] as $c) {
                $t->decimal($c, 14, 2)->default(0);
            }
            $t->boolean('devuelve_stock')->default(false);
        });

        Schema::table('ventas', function (Blueprint $t) {
            $t->decimal('total_notas_credito', 14, 2)->default(0)->after('total');
            $t->decimal('total_notas_debito', 14, 2)->default(0)->after('total_notas_credito');
        });

        Schema::table('pagos', function (Blueprint $t) {
            // reembolso: devolución de dinero al cliente por una nota de crédito (egreso de caja)
            $t->enum('tipo', ['pago', 'reembolso'])->default('pago')->after('venta_id');
            $t->foreignId('comprobante_id')->nullable()->after('tipo')->constrained('comprobantes');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('comprobante_id');
            $t->dropColumn('tipo');
        });
        Schema::table('ventas', fn (Blueprint $t) => $t->dropColumn(['total_notas_credito', 'total_notas_debito']));
        Schema::dropIfExists('comprobante_items');
        Schema::table('comprobantes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('comprobante_referencia_id');
            $t->dropColumn(['cod_motivo', 'des_motivo']);
        });
    }
};
