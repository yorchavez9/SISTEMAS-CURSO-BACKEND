<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->constrained('establecimientos');
            $t->string('nombre', 80);
            $t->boolean('es_principal')->default(false); // de este almacén descuentan las ventas del establecimiento
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['establecimiento_id', 'nombre']);
        });

        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('almacen_id')->constrained('almacenes');
            $t->foreignId('producto_id')->constrained('productos');
            $t->decimal('cantidad', 14, 4)->default(0);
            $t->decimal('costo_promedio', 14, 6)->default(0);
            $t->timestamps();
            $t->unique(['almacen_id', 'producto_id']);
            $t->index(['empresa_id', 'producto_id']);
        });

        // Kardex: cada movimiento guarda el saldo resultante (cantidad y costo promedio ponderado).
        Schema::create('movimientos_inventario', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('almacen_id')->constrained('almacenes');
            $t->foreignId('producto_id')->constrained('productos');
            $t->string('tipo', 25); // entrada, salida, ajuste_entrada, ajuste_salida, venta, transferencia_entrada, transferencia_salida
            $t->enum('sentido', ['entrada', 'salida']);
            $t->decimal('cantidad', 14, 4);
            $t->decimal('costo_unitario', 14, 6);
            $t->decimal('valor', 14, 2);
            $t->decimal('saldo_cantidad', 14, 4);
            $t->decimal('saldo_costo_promedio', 14, 6);
            $t->string('documento', 60)->nullable(); // guía, factura de compra, comprobante de venta…
            $t->string('observacion', 255)->nullable();
            $t->string('origen_type', 60)->nullable();
            $t->unsignedBigInteger('origen_id')->nullable();
            $t->uuid('lote')->nullable(); // agrupa los ítems de una misma operación (p. ej. transferencia)
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['almacen_id', 'producto_id', 'id']);
            $t->index(['empresa_id', 'created_at']);
            $t->index(['origen_type', 'origen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('almacenes');
    }
};
