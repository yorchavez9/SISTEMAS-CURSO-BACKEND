<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    private array $datos = [
        'name' => 'Administrador General',
        'email' => 'Admin@Empresa.pe',
        'password' => 'ClaveSegura2026',
        'password_confirmation' => 'ClaveSegura2026',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:5173/configuracion-inicial');
    }

    public function test_sin_usuarios_requiere_configuracion(): void
    {
        $this->getJson('/api/v1/setup/estado')->assertOk()->assertJsonPath('data.requiere_configuracion', true);
    }

    public function test_registra_el_primer_superadmin_e_inicia_sesion(): void
    {
        $this->postJson('/api/v1/setup/superadmin', $this->datos)
            ->assertCreated()
            ->assertJsonPath('data.email', 'admin@empresa.pe')
            ->assertJsonPath('data.is_superadmin', true);

        $user = User::sole();
        $this->assertTrue($user->is_superadmin);
        $this->assertTrue(Hash::check('ClaveSegura2026', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('setup.superadmin_creado', AuditLog::sole()->evento);
        $this->assertStringNotContainsString('ClaveSegura2026', json_encode(AuditLog::sole()->toArray()));

        $this->getJson('/api/v1/setup/estado')->assertJsonPath('data.requiere_configuracion', false);
    }

    public function test_queda_cerrado_si_ya_existe_algun_usuario(): void
    {
        User::factory()->create();

        $this->postJson('/api/v1/setup/superadmin', $this->datos)->assertForbidden();
        $this->assertSame(1, User::count());
        $this->getJson('/api/v1/setup/estado')->assertJsonPath('data.requiere_configuracion', false);
    }

    public function test_no_permite_un_segundo_registro(): void
    {
        $this->postJson('/api/v1/setup/superadmin', $this->datos)->assertCreated();
        auth()->guard('web')->logout();

        $this->postJson('/api/v1/setup/superadmin', ['email' => 'otro@empresa.pe'] + $this->datos)->assertForbidden();
        $this->assertSame(1, User::count());
    }

    public function test_valida_datos_y_contrasena_robusta(): void
    {
        $this->postJson('/api/v1/setup/superadmin', [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/v1/setup/superadmin', ['password' => 'corta1', 'password_confirmation' => 'corta1'] + $this->datos)
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/setup/superadmin', ['password_confirmation' => 'OtraClave2026'] + $this->datos)
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
    }
}
