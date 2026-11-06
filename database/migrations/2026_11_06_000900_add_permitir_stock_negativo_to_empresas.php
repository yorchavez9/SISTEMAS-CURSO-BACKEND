<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            // false (por defecto): la venta se bloquea si no hay stock. true: se vende y el kardex queda en negativo.
            $t->boolean('permitir_stock_negativo')->default(false)->after('entorno_activo');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('permitir_stock_negativo'));
    }
};
