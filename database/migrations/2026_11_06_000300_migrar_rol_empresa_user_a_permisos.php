<?php

use App\Models\User;
use Database\Seeders\PermisosSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte la columna empresa_user.rol (Etapa 1) en roles de spatie/permission por empresa (team),
 * conservando las asignaciones existentes, y agrega empresa_user.activo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_user', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('user_id');
        });

        (new PermisosSeeder)->run();

        $roles = DB::table('roles')->whereNull('empresa_id')->pluck('id', 'name');
        foreach (DB::table('empresa_user')->get(['empresa_id', 'user_id', 'rol']) as $row) {
            $rolId = $roles[$row->rol] ?? $roles['vendedor'];
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $rolId,
                'model_type' => User::class,
                'model_id' => $row->user_id,
                'empresa_id' => $row->empresa_id,
            ]);
        }

        Schema::table('empresa_user', function (Blueprint $table) {
            $table->dropColumn('rol');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_user', function (Blueprint $table) {
            $table->string('rol', 30)->default('vendedor')->after('user_id');
        });
        $porRol = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_type', User::class)->get(['model_id', 'model_has_roles.empresa_id', 'roles.name']);
        foreach ($porRol as $r) {
            DB::table('empresa_user')->where('user_id', $r->model_id)->where('empresa_id', $r->empresa_id)->update(['rol' => $r->name]);
        }
        Schema::table('empresa_user', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};
