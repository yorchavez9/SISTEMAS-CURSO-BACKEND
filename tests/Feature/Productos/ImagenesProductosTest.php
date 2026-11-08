<?php

namespace Tests\Feature\Productos;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImagenesProductosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(\Database\Seeders\CatalogosSunatSeeder::class);
        $this->empresa = Empresa::factory()->create(['ruc' => '20161515648']);
        $this->admin = User::factory()->create();
        $this->admin->asignarEnEmpresa($this->empresa, 'admin');
        $this->withHeader('X-Empresa-Id', (string) $this->empresa->id);
    }

    private function crear(array $o = []): array
    {
        $cat = Categoria::withoutGlobalScopes()->firstOrCreate(['empresa_id' => $this->empresa->id, 'nombre' => 'BEBIDAS']);

        return $this->actingAs($this->admin)->postJson('/api/v1/productos', $o + ['codigo' => 'BEB-001', 'descripcion' => 'AGUA MINERAL 625 ML',
            'categoria_id' => $cat->id, 'tipo' => 'bien', 'unidad' => 'NIU', 'precio_venta' => 2, 'tip_afe_igv' => '10'])->assertCreated()->json('data');
    }

    public function test_todo_producto_nuevo_recibe_una_ilustracion(): void
    {
        $p = $this->crear();
        $this->assertTrue($p['imagen_generada']);
        $this->assertStringContainsString('/storage/productos/', $p['imagen_url']);
        $ruta = Producto::withoutGlobalScopes()->find($p['id'])->imagen;
        $svg = Storage::disk('public')->get($ruta);
        $this->assertStringContainsString('>AGUA MINERAL 625</text>', $svg); // el nombre se reparte en líneas
        $this->assertStringNotContainsString('<script', $svg);

        // Si cambia el nombre, la ilustración se actualiza (y se borra la anterior).
        $this->actingAs($this->admin)->putJson("/api/v1/productos/{$p['id']}", ['descripcion' => 'AGUA SIN GAS 1 L'])->assertOk();
        $nueva = Producto::withoutGlobalScopes()->find($p['id'])->imagen;
        $this->assertNotSame($ruta, $nueva);
        Storage::disk('public')->assertMissing($ruta);
        $this->assertStringContainsString('AGUA SIN GAS 1 L', Storage::disk('public')->get($nueva));
    }

    public function test_subir_foto_la_reduce_a_webp_y_quitarla_vuelve_a_la_ilustracion(): void
    {
        $p = $this->crear();
        $foto = UploadedFile::fake()->image('agua.jpg', 1600, 1200);
        $r = $this->actingAs($this->admin)->post("/api/v1/productos/{$p['id']}/imagen", ['imagen' => $foto], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.imagen_generada', false)->json('data');
        $ruta = Producto::withoutGlobalScopes()->find($p['id'])->imagen;
        $this->assertStringEndsWith('.webp', $ruta);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($ruta));
        $this->assertSame([800, 600], [$w, $h]);
        $this->assertStringContainsString($ruta, $r['imagen_url']);

        // Editar el producto NO reemplaza la foto subida.
        $this->actingAs($this->admin)->putJson("/api/v1/productos/{$p['id']}", ['descripcion' => 'OTRO NOMBRE'])->assertOk();
        $this->assertSame($ruta, Producto::withoutGlobalScopes()->find($p['id'])->imagen);

        $this->actingAs($this->admin)->deleteJson("/api/v1/productos/{$p['id']}/imagen")->assertOk()->assertJsonPath('data.imagen_generada', true);
        Storage::disk('public')->assertMissing($ruta);
    }

    public function test_valida_el_archivo_y_los_permisos(): void
    {
        $p = $this->crear();
        $this->actingAs($this->admin)->post("/api/v1/productos/{$p['id']}/imagen", ['imagen' => UploadedFile::fake()->create('virus.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('imagen');
        $this->actingAs($this->admin)->post("/api/v1/productos/{$p['id']}/imagen", ['imagen' => UploadedFile::fake()->image('grande.jpg')->size(5000)], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $vendedor = User::factory()->create();
        $vendedor->asignarEnEmpresa($this->empresa, 'vendedor');
        $this->actingAs($vendedor)->post("/api/v1/productos/{$p['id']}/imagen", ['imagen' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_comando_genera_imagenes_de_productos_sin_imagen(): void
    {
        Producto::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'codigo' => 'P1', 'descripcion' => 'SIN IMAGEN', 'unidad' => 'NIU', 'precio_venta' => 1, 'tip_afe_igv' => '10']);
        $this->artisan('productos:imagenes')->expectsOutput('Imágenes generadas o actualizadas: 1')->assertSuccessful();
        $this->artisan('productos:imagenes')->expectsOutput('Imágenes generadas o actualizadas: 0')->assertSuccessful();
        $this->assertNotNull(Producto::withoutGlobalScopes()->where('codigo', 'P1')->value('imagen'));
    }
}
