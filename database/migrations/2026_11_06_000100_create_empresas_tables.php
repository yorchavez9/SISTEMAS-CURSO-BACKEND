<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->char('ruc', 11)->unique();
            $table->string('razon_social', 255);
            $table->string('nombre_comercial', 255)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->char('ubigeo', 6)->nullable();
            $table->string('departamento', 60)->nullable();
            $table->string('provincia', 60)->nullable();
            $table->string('distrito', 60)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            // Entorno con el que el sistema emite: credenciales de "pruebas" o de "produccion".
            $table->enum('entorno_activo', ['pruebas', 'produccion'])->default('pruebas');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('empresa_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rol', 30)->default('vendedor'); // migrado a spatie/permission en 2026_11_06_000300
            $table->timestamps();
            $table->unique(['empresa_id', 'user_id']);
        });

        Schema::create('empresa_api_credenciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->enum('entorno', ['pruebas', 'produccion']);
            $table->text('api_key');     // cifrado (cast encrypted)
            $table->text('api_secret');  // cifrado (cast encrypted)
            $table->char('api_key_ultimos4', 4)->nullable();
            $table->timestamp('verificado_at')->nullable();
            $table->boolean('verificacion_ok')->nullable();
            $table->string('verificacion_mensaje', 500)->nullable();
            $table->json('remoto')->nullable(); // datos leídos de GET /empresa y /suscripcion (sin secretos)
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['empresa_id', 'entorno']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evento', 60);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['empresa_id', 'auditable_type', 'auditable_id']);
            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('empresa_api_credenciales');
        Schema::dropIfExists('empresa_user');
        Schema::dropIfExists('empresas');
    }
};
