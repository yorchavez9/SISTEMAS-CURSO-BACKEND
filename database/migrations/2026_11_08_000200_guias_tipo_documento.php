<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Guías de remisión: 09 remitente (las existentes) o 31 transportista. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_remision', function (Blueprint $t) {
            $t->char('tipo_documento', 2)->default('09')->after('serie_id');
        });
    }

    public function down(): void
    {
        Schema::table('guias_remision', function (Blueprint $t) {
            $t->dropColumn('tipo_documento');
        });
    }
};
