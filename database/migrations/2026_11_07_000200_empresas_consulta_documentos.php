<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedor para consultar RUC (SUNAT) y DNI (RENIEC) al registrar clientes:
 *  - api_facturacion: GET /buscar-documento de la API de facturación (incluido, sin token adicional).
 *  - decolecta: api.decolecta.com con token propio (cifrado con APP_KEY); si falla, se usa la API de facturación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            $t->string('consulta_proveedor', 30)->default('api_facturacion')->after('envio_boletas');
            $t->text('consulta_token')->nullable()->after('consulta_proveedor');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            $t->dropColumn(['consulta_proveedor', 'consulta_token']);
        });
    }
};
