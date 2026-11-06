<?php

namespace Tests\Feature\Empresas;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpresasYAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // El SPA llega por el proxy de Vite: Sanctum trata la petición como "stateful" (cookie de sesión).
        $this->withHeader('Referer', 'http://localhost:5173/login');
    }

    public function test_login_por_sesion_y_me(): void
    {
        $user = User::factory()->create(['email' => 'admin@demo.pe', 'is_superadmin' => true]);
        Empresa::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@demo.pe', 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.email', 'admin@demo.pe')->assertJsonCount(1, 'data.empresas');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_falla_con_clave_incorrecta_o_usuario_inactivo(): void
    {
        User::factory()->create(['email' => 'a@demo.pe']);
        User::factory()->create(['email' => 'b@demo.pe', 'activo' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@demo.pe', 'password' => 'mala'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'b@demo.pe', 'password' => 'password'])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_superadmin_registra_empresa_y_queda_como_admin(): void
    {
        $su = User::factory()->create(['is_superadmin' => true]);
        $this->actingAs($su)->postJson('/api/v1/empresas', ['ruc' => '20161515648', 'razon_social' => 'EMPRESA EJEMPLOS SAC'])
            ->assertCreated()->assertJsonPath('data.ruc', '20161515648')->assertJsonPath('data.entorno_activo', 'pruebas');

        $this->actingAs($su)->postJson('/api/v1/empresas', ['ruc' => '20161515648', 'razon_social' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('ruc');
        $this->actingAs($su)->postJson('/api/v1/empresas', ['ruc' => '123', 'razon_social' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('ruc');
    }

    public function test_usuario_comun_no_crea_empresas_ni_ve_las_ajenas(): void
    {
        $user = User::factory()->create();
        $propia = Empresa::factory()->create();
        $ajena = Empresa::factory()->create();
        $user->asignarEnEmpresa($propia, 'vendedor');

        $this->actingAs($user)->postJson('/api/v1/empresas', ['ruc' => '20161515648', 'razon_social' => 'X'])->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/empresas')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $propia->id);
        $this->actingAs($user)->getJson("/api/v1/empresas/{$ajena->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/empresas/{$propia->id}", ['entorno_activo' => 'produccion'])->assertForbidden();
    }
}
