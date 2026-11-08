<?php

namespace Tests\Feature\Empresas;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EliminarEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_elimina_con_confirmacion_conserva_datos_y_restaura(): void
    {
        Http::fake();
        $super = User::factory()->create(['is_superadmin' => true]);
        $e = Empresa::factory()->create(['ruc' => '20161515648', 'razon_social' => 'DEMO SAC']);
        $super->asignarEnEmpresa($e, 'admin');

        $this->actingAs($super)->deleteJson("/api/v1/empresas/{$e->id}", ['confirmacion' => '20100000001'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->actingAs($super)->deleteJson("/api/v1/empresas/{$e->id}", ['confirmacion' => '20161515648'])->assertOk();

        $this->assertSoftDeleted($e);
        $this->actingAs($super)->getJson('/api/v1/empresas')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($super)->getJson('/api/v1/empresas?eliminadas=1')->assertOk()->assertJsonPath('data.0.ruc', '20161515648');
        $this->actingAs($super)->getJson('/api/v1/auth/me')->assertOk()->assertJsonCount(0, 'data.empresas');
        Http::assertNothingSent(); // no se toca la cuenta de la API de facturación

        $this->actingAs($super)->postJson("/api/v1/empresas/{$e->id}/restaurar")->assertOk()->assertJsonPath('data.eliminada_at', null);
        $this->assertNotSoftDeleted($e);
    }

    public function test_un_administrador_de_empresa_no_puede_eliminarla(): void
    {
        $e = Empresa::factory()->create(['ruc' => '20161515648']);
        $admin = User::factory()->create();
        $admin->asignarEnEmpresa($e, 'admin');
        $this->actingAs($admin)->deleteJson("/api/v1/empresas/{$e->id}", ['confirmacion' => '20161515648'])->assertForbidden();
        $this->assertNotSoftDeleted($e);
    }
}
