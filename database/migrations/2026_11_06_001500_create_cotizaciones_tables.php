<?php

use Database\Seeders\PermisosSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones: documento interno de preventa (no tributario). Se gestionan solo en el sistema porque
 * POST /cotizaciones de la API responde 500 (hallazgo H-13). Totales con el motor tributario validado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('numero');               // correlativo por empresa: COT-000001
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $t->char('cliente_tipo_doc', 1)->nullable();
            $t->string('cliente_num_doc', 15)->nullable();
            $t->string('cliente_razon_social', 250);
            $t->string('cliente_email', 150)->nullable();
            $t->date('fecha_emision');
            $t->date('fecha_validez');
            $t->char('moneda', 3)->default('PEN');
            $t->decimal('tasa_igv', 5, 2);
            foreach (['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'igv', 'isc', 'icbper', 'descuentos', 'total'] as $c) {
                $t->decimal($c, 14, 2)->default(0);
            }
            $t->string('observacion', 1000)->nullable();
            $t->enum('estado', ['borrador', 'enviada', 'aceptada', 'rechazada', 'convertida'])->default('borrador');
            $t->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'numero']);
            $t->index(['empresa_id', 'estado']);
            $t->index(['empresa_id', 'fecha_emision']);
        });

        Schema::create('cotizacion_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $t->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $t->string('codigo', 30)->nullable();
            $t->string('descripcion', 255);
            $t->string('unidad', 3);
            $t->decimal('cantidad', 14, 4);
            $t->decimal('precio_unitario', 14, 6);
            $t->char('tip_afe_igv', 2);
            $t->decimal('descuento', 14, 2)->default(0);
            $t->decimal('base', 14, 2);
            $t->decimal('igv', 14, 2);
            $t->decimal('total', 14, 2);
        });

        Schema::table('ventas', function (Blueprint $t) {
            $t->foreignId('cotizacion_id')->nullable()->after('cliente_id')->constrained('cotizaciones')->nullOnDelete();
        });

        (new PermisosSeeder)->run(); // permisos cotizaciones.*
    }

    public function down(): void
    {
        Schema::table('ventas', fn (Blueprint $t) => $t->dropConstrainedForeignId('cotizacion_id'));
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');
    }
};
