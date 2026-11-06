<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('tipo_documento', 1);      // catálogo 06
            $table->string('numero_documento', 15);
            $table->string('razon_social', 255);       // razón social o nombres y apellidos
            $table->string('nombre_comercial', 255)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->char('ubigeo', 6)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->decimal('limite_credito', 14, 2)->nullable();
            $table->unsignedSmallInteger('dias_credito')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['empresa_id', 'tipo_documento', 'numero_documento']);
            $table->index(['empresa_id', 'razon_social']);
        });

        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->enum('tipo', ['bien', 'servicio'])->default('bien');
            $table->string('codigo', 30);
            $table->string('codigo_barras', 50)->nullable();
            $table->char('cod_producto_sunat', 8)->nullable(); // catálogo UNSPSC de SUNAT
            $table->string('descripcion', 255);
            $table->string('unidad', 3);                        // catálogo 03
            $table->char('moneda', 3)->default('PEN');
            // Precio de venta CON impuestos (convención de la API: precio_unitario incluye IGV/ISC).
            $table->decimal('precio_venta', 14, 6)->default(0);
            $table->decimal('costo', 14, 6)->default(0);
            $table->char('tip_afe_igv', 2);
            $table->foreign('tip_afe_igv')->references('codigo')->on('tipos_afectacion_igv');
            $table->decimal('porcentaje_isc', 6, 2)->nullable();
            $table->char('tip_sis_isc', 2)->nullable();
            $table->boolean('aplica_icbper')->default(false);
            $table->decimal('factor_icbper', 8, 4)->nullable();
            $table->boolean('controla_stock')->default(true);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['empresa_id', 'codigo']);
            $table->index(['empresa_id', 'descripcion']);
            $table->index(['empresa_id', 'codigo_barras']);
        });

        Schema::create('producto_presentaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('nombre', 60);                 // "Caja x 12", "Docena"
            $table->string('unidad', 3);
            $table->decimal('factor', 14, 6);             // unidades base por presentación
            $table->decimal('precio_venta', 14, 6);
            $table->string('codigo_barras', 50)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_presentaciones');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('clientes');
    }
};
