<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Modules\Productos\Services\ImagenProductoService;
use Illuminate\Console\Command;

/** Genera la ilustración de los productos sin imagen (o regenera las ilustraciones, sin tocar las fotos subidas). */
class GenerarImagenesProductos extends Command
{
    protected $signature = 'productos:imagenes {--empresa= : ID de una empresa concreta} {--regenerar : Vuelve a dibujar las ilustraciones existentes}';

    protected $description = 'Asigna una imagen ilustrada (color e ícono de la categoría) a los productos que no tienen foto';

    public function handle(ImagenProductoService $imagenes): int
    {
        $n = 0;
        Producto::withoutGlobalScopes()->with('categoria')->whereNull('deleted_at')
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('empresa_id', $id))
            ->when(! $this->option('regenerar'), fn ($q) => $q->whereNull('imagen'))
            ->orderBy('id')->each(function (Producto $p) use ($imagenes, &$n) {
                $antes = $p->imagen;
                $imagenes->generar($p);
                $n += $p->imagen !== $antes ? 1 : 0;
            });
        $this->info("Imágenes generadas o actualizadas: $n");

        return self::SUCCESS;
    }
}
