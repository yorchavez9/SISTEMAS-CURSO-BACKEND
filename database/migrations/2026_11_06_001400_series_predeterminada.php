<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serie predeterminada por tipo de documento (y por familia F/B en notas). Es una preferencia LOCAL:
 * no se envía a la API, así que no afecta a otros sistemas que usen la misma cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $t) {
            $t->boolean('predeterminada')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('series', fn (Blueprint $t) => $t->dropColumn('predeterminada'));
    }
};
