<?php

namespace Tests\Feature\Auditoria;

use App\Models\AuditLog;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = User::factory()->create(['name' => 'Ana Admin']);
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
    }

    private function log(string $evento, ?int $empresaId = null, ?int $userId = null, string $fecha = '2026-11-06 10:00:00'): AuditLog
    {
        $a = AuditLog::create(['empresa_id' => $empresaId ?? $this->empresa->id, 'user_id' => $userId, 'evento' => $evento,
            'auditable_type' => 'App\\Models\\Venta', 'auditable_id' => 7, 'new_values' => ['total' => 118], 'ip' => '127.0.0.1']);
        $a->forceFill(['created_at' => $fecha])->save();

        return $a;
    }

    public function test_lista_eventos_de_la_empresa_con_descripcion_y_filtros(): void
    {
        $this->log('venta.registrada', userId: $this->admin->id);
        $this->log('caja.abierta', userId: $this->admin->id, fecha: '2026-11-05 09:00:00');
        $this->log('pago.reembolso'); // generado por un proceso sin usuario
        $this->log('venta.registrada', empresaId: Empresa::factory()->create()->id); // otra empresa: no se ve

        $r = $this->actingAs($this->admin)->getJson('/api/v1/auditoria')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame(['Reembolso', 'Caja abierta', 'Venta registrada'], array_column($r->json('data'), 'descripcion'));
        $this->assertSame(['Venta #7', 'Ana Admin', null], [$r->json('data.2.objeto'), $r->json('data.2.usuario'), $r->json('data.0.usuario')]);

        $this->actingAs($this->admin)->getJson('/api/v1/auditoria?modulo=venta')->assertJsonPath('meta.total', 1);
        $this->actingAs($this->admin)->getJson('/api/v1/auditoria?desde=2026-11-06&hasta=2026-11-06')->assertJsonPath('meta.total', 2);
        $this->actingAs($this->admin)->getJson("/api/v1/auditoria?user_id={$this->admin->id}")->assertJsonPath('meta.total', 2);
        $this->actingAs($this->admin)->getJson('/api/v1/auditoria?modulo=x%25')->assertStatus(422); // sin comodines en el filtro

        $this->actingAs($this->admin)->getJson('/api/v1/auditoria/filtros')->assertOk()
            ->assertJsonPath('data.modulos', ['caja', 'pago', 'venta'])->assertJsonPath('data.usuarios.0.name', 'Ana Admin');
    }

    public function test_permisos(): void
    {
        $contador = User::factory()->create();
        $contador->asignarEnEmpresa($this->empresa, 'contador');
        $this->actingAs($contador)->getJson('/api/v1/auditoria')->assertOk();

        $vendedor = User::factory()->create();
        $vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($vendedor)->getJson('/api/v1/auditoria')->assertForbidden();
    }
}
