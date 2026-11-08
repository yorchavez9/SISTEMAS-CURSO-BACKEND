<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL base de la API de facturación por empresa y entorno (se ingresa en Configuración → Credenciales API).
 * Vacía = la de SUNAT_API_BASE_URL del .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_api_credenciales', function (Blueprint $t) {
            $t->string('base_url', 255)->nullable()->after('entorno');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_api_credenciales', function (Blueprint $t) {
            $t->dropColumn('base_url');
        });
    }
};
