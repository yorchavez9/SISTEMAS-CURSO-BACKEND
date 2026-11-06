<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('establecimiento_id')->constrained('establecimientos');
            $t->string('nombre', 60);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['establecimiento_id', 'nombre']);
        });

        Schema::create('caja_sesiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('caja_id')->constrained('cajas');
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('apertura_at');
            $t->decimal('monto_apertura', 14, 2);
            $t->timestamp('cierre_at')->nullable();
            $t->foreignId('cerrado_por')->nullable()->constrained('users');
            $t->decimal('efectivo_esperado', 14, 2)->nullable();
            $t->decimal('efectivo_contado', 14, 2)->nullable();
            $t->decimal('diferencia', 14, 2)->nullable();
            $t->json('resumen_cierre')->nullable();
            $t->string('observacion', 500)->nullable();
            $t->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            // 1 mientras está abierta, NULL al cerrar: el índice único impide dos sesiones abiertas en la misma caja.
            $t->boolean('abierta_flag')->nullable()->default(true);
            $t->timestamps();
            $t->unique(['caja_id', 'abierta_flag']);
            $t->index(['empresa_id', 'user_id', 'estado']);
        });

        Schema::create('caja_movimientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('caja_sesion_id')->constrained('caja_sesiones');
            $t->enum('tipo', ['ingreso', 'egreso']);
            $t->string('concepto', 30); // venta, cobranza, anulacion_pago, ingreso_manual, gasto, retiro
            $t->string('metodo', 20);
            $t->decimal('monto', 14, 2);
            $t->string('descripcion', 255)->nullable();
            $t->string('origen_type', 60)->nullable();
            $t->unsignedBigInteger('origen_id')->nullable();
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['caja_sesion_id', 'metodo']);
        });

        Schema::table('ventas', function (Blueprint $t) {
            $t->decimal('monto_pagado', 14, 2)->default(0)->after('total');
            $t->decimal('saldo', 14, 2)->default(0)->after('monto_pagado');
            $t->enum('estado_pago', ['pendiente', 'parcial', 'pagado'])->default('pendiente')->after('saldo');
        });
        Schema::table('venta_cuotas', function (Blueprint $t) {
            $t->decimal('monto_pagado', 14, 2)->default(0);
        });
        // Ventas existentes: aún no tienen pagos registrados → saldo = total.
        \Illuminate\Support\Facades\DB::table('ventas')->update([
            'saldo' => \Illuminate\Support\Facades\DB::raw('total'),
            'estado_pago' => \Illuminate\Support\Facades\DB::raw("CASE WHEN total > 0 THEN 'pendiente' ELSE 'pagado' END"),
        ]);

        Schema::create('pagos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('venta_id')->constrained('ventas');
            $t->foreignId('caja_sesion_id')->constrained('caja_sesiones');
            $t->string('metodo', 20);
            $t->decimal('monto', 14, 2);              // importe aplicado a la venta
            $t->decimal('monto_recibido', 14, 2)->nullable(); // efectivo entregado por el cliente
            $t->decimal('vuelto', 14, 2)->default(0);
            $t->string('referencia', 100)->nullable();  // N.º de operación, voucher…
            $t->enum('estado', ['registrado', 'anulado'])->default('registrado');
            $t->foreignId('user_id')->constrained('users');
            $t->timestamp('anulado_at')->nullable();
            $t->foreignId('anulado_por')->nullable()->constrained('users');
            $t->string('motivo_anulacion', 255)->nullable();
            $t->timestamps();
            $t->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
        Schema::table('venta_cuotas', fn (Blueprint $t) => $t->dropColumn('monto_pagado'));
        Schema::table('ventas', fn (Blueprint $t) => $t->dropColumn(['monto_pagado', 'saldo', 'estado_pago']));
        Schema::dropIfExists('caja_movimientos');
        Schema::dropIfExists('caja_sesiones');
        Schema::dropIfExists('cajas');
    }
};
