<?php

namespace Tests\Feature\Usuarios;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuariosEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
    }

    private function url(string $sufijo = ''): string
    {
        return "/api/v1/empresas/{$this->empresa->id}/usuarios$sufijo";
    }

    public function test_roles_por_empresa_son_independientes(): void
    {
        $otra = Empresa::factory()->create();
        $u = User::factory()->create();
        $u->asignarEnEmpresa($this->empresa, 'vendedor');
        $u->asignarEnEmpresa($otra, 'admin');

        $this->assertSame(['vendedor'], $u->rolesEn($this->empresa));
        $this->assertSame(['admin'], $u->rolesEn($otra));
        $this->assertTrue($u->puede($this->empresa, 'ventas.crear'));
        $this->assertFalse($u->puede($this->empresa, 'usuarios.gestionar'));
        $this->assertTrue($u->puede($otra, 'usuarios.gestionar'));
        $this->assertFalse($u->puede(Empresa::factory()->create(), 'ventas.ver'));
    }

    public function test_admin_crea_usuario_con_rol(): void
    {
        $this->actingAs($this->admin)->postJson($this->url(), [
            'name' => 'Ana Vendedora', 'email' => 'ana@demo.pe', 'rol' => 'vendedor',
            'password' => 'ClaveSegura2026', 'password_confirmation' => 'ClaveSegura2026',
        ])->assertCreated()->assertJsonPath('data.roles', ['vendedor'])->assertJsonPath('data.activo', true);

        $ana = User::where('email', 'ana@demo.pe')->sole();
        $this->assertTrue($ana->puede($this->empresa, 'comprobantes.emitir'));
        $this->actingAs($this->admin)->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_agrega_usuario_existente_sin_cambiar_su_clave(): void
    {
        $otra = Empresa::factory()->create();
        $existente = User::factory()->create(['email' => 'luis@demo.pe']);
        $existente->asignarEnEmpresa($otra, 'admin');

        $this->actingAs($this->admin)->postJson($this->url(), ['email' => 'luis@demo.pe', 'rol' => 'contador'])
            ->assertCreated()->assertJsonPath('data.roles', ['contador']);
        $this->assertSame(['admin'], $existente->fresh()->rolesEn($otra));

        $this->actingAs($this->admin)->postJson($this->url(), ['email' => 'luis@demo.pe', 'rol' => 'vendedor'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_vendedor_no_gestiona_usuarios(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->asignarEnEmpresa($this->empresa, 'vendedor');

        $this->actingAs($vendedor)->getJson($this->url())->assertForbidden();
        $this->actingAs($vendedor)->postJson($this->url(), ['email' => 'x@demo.pe', 'rol' => 'admin'])->assertForbidden();
    }

    public function test_no_se_queda_sin_administrador(): void
    {
        $this->actingAs($this->admin)->putJson($this->url("/{$this->admin->id}"), ['rol' => 'vendedor'])
            ->assertStatus(422)->assertJsonValidationErrors('rol');

        $otro = User::factory()->create();
        $otro->asignarEnEmpresa($this->empresa, 'admin');
        $this->actingAs($this->admin)->putJson($this->url("/{$this->admin->id}"), ['rol' => 'supervisor'])->assertOk();
        $this->assertSame(['supervisor'], $this->admin->fresh()->rolesEn($this->empresa));
    }

    public function test_desactivar_y_retirar(): void
    {
        $u = User::factory()->create();
        $u->asignarEnEmpresa($this->empresa, 'vendedor');

        $this->actingAs($this->admin)->putJson($this->url("/{$u->id}"), ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);
        $this->assertFalse($u->fresh()->puede($this->empresa, 'ventas.ver'));

        $this->actingAs($this->admin)->deleteJson($this->url("/{$u->id}"))->assertOk();
        $this->assertFalse($u->fresh()->empresas()->whereKey($this->empresa->id)->exists());
        $this->assertSame([], $u->fresh()->rolesEn($this->empresa));

        $this->actingAs($this->admin)->deleteJson($this->url("/{$this->admin->id}"))->assertStatus(422);
    }

    public function test_admin_de_empresa_no_modifica_ni_retira_al_superadmin(): void
    {
        $super = User::factory()->create(['is_superadmin' => true]);
        $super->empresas()->attach($this->empresa->id, ['activo' => true]);

        $this->actingAs($this->admin)->putJson($this->url("/{$super->id}"), ['activo' => false])->assertNotFound();
        $this->actingAs($this->admin)->deleteJson($this->url("/{$super->id}"))->assertNotFound();
        $this->assertTrue($super->fresh()->empresas()->whereKey($this->empresa->id)->exists());
    }

    public function test_me_expone_permisos_por_empresa(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $res = $this->actingAs($vendedor)->getJson('/api/v1/auth/me')->assertOk();
        $permisos = $res->json('data.empresas.0.mis_permisos');
        $this->assertContains('ventas.crear', $permisos);
        $this->assertNotContains('credenciales_api.gestionar', $permisos);
        $this->assertSame(['vendedor'], $res->json('data.empresas.0.mis_roles'));
    }
}
