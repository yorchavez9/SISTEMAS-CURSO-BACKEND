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
use App\Modules\Inventario\Services\InventarioService;
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

    public function test_sin_empresa_no_falla_ni_crea_nada(): void
    {
        $this->seed(DemoSeeder::class);
        $this->assertSame(0, Producto::withoutGlobalScopes()->count());
    }

    public function test_crea_datos_demo_con_stock_y_es_idempotente(): void
    {
        $empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        User::factory()->create(['is_superadmin' => true]);
        foreach (['0000', '0001'] as $cod) {
            $est = Establecimiento::create(['empresa_id' => $empresa->id, 'nombre' => "Local $cod", 'cod_local' => $cod]);
            app(InventarioService::class)->almacenPrincipal($empresa->id, $est->id);
        }

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class); // segunda vez: no duplica

        $this->assertSame(9, Categoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
        $this->assertSame(28, Producto::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
        $this->assertSame(4, ProductoPresentacion::count());
        $this->assertSame(5, Cliente::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());

        $papa = Producto::withoutGlobalScopes()->where('codigo', 'FRU-001')->sole();
        $this->assertSame('20', $papa->tip_afe_igv);
        $this->assertTrue((bool) Producto::withoutGlobalScopes()->where('codigo', 'EMP-001')->value('aplica_icbper'));
        $this->assertFalse((bool) Producto::withoutGlobalScopes()->where('codigo', 'SER-001')->value('controla_stock'));

        // 25 bienes con stock en cada uno de los 2 almacenes, sin duplicar en la segunda ejecución.
        $this->assertSame(50, Stock::withoutGlobalScopes()->where('cantidad', '>', 0)->count());
        $this->assertSame('8.0000', (string) Stock::withoutGlobalScopes()->where('producto_id', Producto::withoutGlobalScopes()->where('codigo', 'TEC-001')->value('id'))->value('cantidad'));
    }
}
