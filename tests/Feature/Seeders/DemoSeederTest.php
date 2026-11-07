<?php

namespace Tests\Feature\Seeders;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Stock;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_base_carga_catalogos_sunat(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertGreaterThan(0, DB::table('tipos_afectacion_igv')->count());
        $this->assertTrue(DB::table('sunat_catalogos')->where('catalogo', '03')->where('codigo', 'NIU')->exists());
        $this->seed(DatabaseSeeder::class); // idempotente
    }

    public function test_catalogos_consultados_antes_del_seed_no_quedan_vacios_en_cache(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        DB::table('sunat_catalogos')->delete();
        DB::table('tipos_afectacion_igv')->delete();
        $u = User::factory()->create(['is_superadmin' => true]);

        // Producción recién migrada: se consulta antes de db:seed.
        $this->actingAs($u)->getJson('/api/v1/catalogos')->assertOk()->assertJsonCount(0, 'data.afectaciones_igv');
        $this->assertSame([], \App\Modules\Tributos\Calculo\ReglaAfectacion::todas());

        $this->seed(DatabaseSeeder::class);
        $this->actingAs($u)->getJson('/api/v1/catalogos')->assertOk()
            ->assertJsonPath('data.afectaciones_igv.0.codigo', '10')->assertJsonFragment(['codigo' => 'NIU']);
        $this->assertArrayHasKey('10', \App\Modules\Tributos\Calculo\ReglaAfectacion::todas());
    }

    public function test_sin_empresa_no_falla_ni_crea_nada(): void
    {
        $this->seed(DemoSeeder::class);
        $this->assertSame(0, Producto::withoutGlobalScopes()->count());
    }

    public function test_crea_datos_demo_con_stock_y_es_idempotente(): void
    {
        $empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        User::factory()->create(['is_superadmin' => true]);
        // Como en producción tras sincronizar: establecimientos SIN almacenes (se crean al abrir Inventario).
        foreach (['0000', '0001'] as $cod) {
            Establecimiento::create(['empresa_id' => $empresa->id, 'nombre' => "Local $cod", 'cod_local' => $cod]);
        }
        // Producto creado por el usuario antes del seeder: también recibe stock.
        $propio = Producto::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'codigo' => 'P001', 'descripcion' => 'MOUSE', 'unidad' => 'NIU',
            'precio_venta' => 70, 'costo' => 60, 'tip_afe_igv' => '10', 'controla_stock' => true, 'stock_minimo' => 3]);

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class); // segunda vez: no duplica

        $this->assertSame(9, Categoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
        $this->assertSame(29, Producto::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
        $this->assertSame(4, ProductoPresentacion::count());
        $this->assertSame(5, Cliente::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());

        $papa = Producto::withoutGlobalScopes()->where('codigo', 'FRU-001')->sole();
        $this->assertSame('20', $papa->tip_afe_igv);
        $this->assertTrue((bool) Producto::withoutGlobalScopes()->where('codigo', 'EMP-001')->value('aplica_icbper'));
        $this->assertFalse((bool) Producto::withoutGlobalScopes()->where('codigo', 'SER-001')->value('controla_stock'));

        // 26 bienes (25 demo + el propio) con stock en cada uno de los 2 almacenes creados por el seeder, sin duplicar.
        $this->assertSame(2, \App\Models\Almacen::withoutGlobalScopes()->count());
        $this->assertSame(52, Stock::withoutGlobalScopes()->where('cantidad', '>', 0)->count());
        $this->assertSame(['10.0000', '10.0000'], Stock::withoutGlobalScopes()->where('producto_id', $propio->id)->pluck('cantidad')->map(fn ($c) => (string) $c)->all());
        $this->assertSame('8.0000', (string) Stock::withoutGlobalScopes()->where('producto_id', Producto::withoutGlobalScopes()->where('codigo', 'TEC-001')->value('id'))->value('cantidad'));
    }
}
