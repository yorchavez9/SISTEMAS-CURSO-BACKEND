<?php

namespace Tests\Feature\Tributos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogosTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogo_07_completo_con_evidencia_de_la_etapa_1(): void
    {
        $res = $this->actingAs(User::factory()->create())->getJson('/api/v1/catalogos')->assertOk();
        $afe = collect($res->json('data.afectaciones_igv'))->keyBy('codigo');

        $this->assertCount(19, $afe);
        $this->assertSame(['10', '11', '12', '13', '14', '15', '16', '17', '20', '21', '30', '31', '32', '33', '34', '35', '36', '37', '40'], $afe->keys()->map(fn ($k) => (string) $k)->all());
        // 18 aceptados en beta; el 37 rechazado (2640) por defecto de la API → deshabilitado.
        $this->assertSame(18, $afe->where('validado_api', true)->count());
        $this->assertFalse($afe['37']['validado_api']);
        $this->assertFalse($afe['37']['activo']);
        $this->assertStringContainsString('2640', $afe['37']['evidencia']);
        // Reglas de cálculo
        $this->assertSame(['codigo' => '1000', 'nombre' => 'IGV', 'tipo' => 'VAT'], $afe['10']['tributo']);
        $this->assertTrue($afe['13']['es_gratuito']);
        $this->assertTrue($afe['13']['calcula_igv']);
        $this->assertFalse($afe['32']['calcula_igv']);
        $this->assertSame('1016', $afe['17']['tributo']['codigo']);
        $this->assertSame('9995', $afe['40']['tributo']['codigo']);
    }

    public function test_catalogos_generales(): void
    {
        $cat = $this->actingAs(User::factory()->create())->getJson('/api/v1/catalogos')->json('data.catalogos');
        $this->assertSame(['0', '1', '4', '6', '7', 'A'], array_column($cat['06'], 'codigo'));
        $this->assertSame(['PEN', 'USD'], array_column($cat['02'], 'codigo'));
        $this->assertContains('13', array_column($cat['09'], 'codigo'));
        $this->assertSame(2, collect($cat['22'])->firstWhere('codigo', '01')['meta']['tasa']);
    }

    public function test_requiere_sesion(): void
    {
        $this->getJson('/api/v1/catalogos')->assertUnauthorized();
    }
}
