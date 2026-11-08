<?php

namespace App\Modules\Productos\Services;

use App\Models\Producto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imagen de cada producto, en el disco "public" (se sirve como /storage/... y requiere `php artisan storage:link`).
 *  - Foto subida: se reduce a 800 px como máximo y se guarda en WebP (si el servidor no tiene GD, se guarda tal cual).
 *  - Ilustración generada: SVG con el color y el ícono de la categoría y el nombre del producto. Todo producto sin
 *    foto recibe una, así nunca queda sin imagen; el usuario puede reemplazarla por una foto en cualquier momento.
 */
class ImagenProductoService
{
    private const MAX_LADO = 800;

    private const COLORES = [
        ['#2563eb', '#1e40af'], ['#7c3aed', '#5b21b6'], ['#0891b2', '#155e75'], ['#d97706', '#92400e'], ['#16a34a', '#166534'],
        ['#0284c7', '#075985'], ['#db2777', '#9d174d'], ['#ea580c', '#9a3412'], ['#4f46e5', '#3730a3'], ['#0d9488', '#115e59'],
    ];

    /** Íconos de trazo (24×24) según palabras de la categoría. */
    private const ICONOS = [
        'accesorio|periferic' => '<rect x="7" y="3" width="10" height="18" rx="5"/><path d="M12 7v4"/>', // antes que "computo"
        'tecnolog|computo|comput|laptop|electr' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
        'oficina|papel|libr|util' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'abarrote|despensa|aliment|comida' => '<path d="M3 9h18l-2 11H5z"/><path d="M8 9l4-6 4 6M9 13v4M15 13v4"/>',
        'fruta|verdura|fresco' => '<path d="M12 7c-3-2-7 0-7 5s3 9 5 9c1 0 1.5-.5 2-.5s1 .5 2 .5c2 0 5-4 5-9s-4-7-7-5z"/><path d="M12 7c0-2 1-4 3-4"/>',
        'bebida|agua|gaseosa|jugo' => '<path d="M10 2h4v4l2 3v12a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V9l2-3z"/><path d="M8 13h8"/>',
        'limpieza|aseo|higiene' => '<path d="M8 9h6v12H8z"/><path d="M10 9V5h4l3 2M18 4h2M18 8h2"/>',
        'empaque|bolsa|embalaje' => '<path d="M5 8h14l-1 13H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'servicio|soporte|manten|tecnico' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-2.5z"/>',
    ];

    private const ICONO_GENERICO = '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>';

    public function url(Producto $p): ?string
    {
        return $p->imagen ? Storage::disk('public')->url($p->imagen) : null;
    }

    public function esGenerada(Producto $p): bool
    {
        return $p->imagen !== null && str_contains($p->imagen, '-auto-');
    }

    /** Guarda la foto subida por el usuario (reemplaza la anterior). */
    public function subir(Producto $p, UploadedFile $archivo): void
    {
        $base = "productos/{$p->empresa_id}/{$p->id}-".Str::lower(Str::random(8));
        $webp = $this->reducirAWebp($archivo->getRealPath());
        if ($webp !== null) {
            $ruta = "$base.webp";
            Storage::disk('public')->put($ruta, $webp);
        } else {
            $ruta = "$base.".($archivo->guessExtension() ?: 'jpg');
            Storage::disk('public')->put($ruta, file_get_contents($archivo->getRealPath()));
        }
        $this->reemplazar($p, $ruta);
    }

    /** Quita la foto y vuelve a la ilustración generada. */
    public function quitar(Producto $p): void
    {
        $this->generar($p, forzar: true);
    }

    /**
     * Genera la ilustración si el producto no tiene imagen (o si $forzar, o si la actual también es generada y
     * cambió el nombre o la categoría). Nunca reemplaza una foto subida salvo con $forzar.
     */
    public function generar(Producto $p, bool $forzar = false): void
    {
        if ($p->imagen && ! $forzar && ! $this->esGenerada($p)) {
            return;
        }
        $svg = $this->svg($p);
        $ruta = "productos/{$p->empresa_id}/{$p->id}-auto-".substr(md5($svg), 0, 8).'.svg';
        if ($ruta === $p->imagen && Storage::disk('public')->exists($ruta)) {
            return;
        }
        Storage::disk('public')->put($ruta, $svg);
        $this->reemplazar($p, $ruta);
    }

    public function svg(Producto $p): string
    {
        $categoria = $p->categoria?->nombre ?? '';
        [$c1, $c2] = self::COLORES[abs(crc32(Str::upper($categoria ?: 'GENERAL'))) % count(self::COLORES)];
        $icono = self::ICONO_GENERICO;
        foreach (self::ICONOS as $patron => $trazo) {
            if (preg_match("/($patron)/i", Str::ascii($categoria))) {
                $icono = $trazo;
                break;
            }
        }
        $lineas = array_slice(explode("\n", wordwrap(Str::upper($p->descripcion), 18, "\n", true)), 0, 3);
        $y0 = 268 - (count($lineas) - 1) * 13;
        $texto = '';
        foreach ($lineas as $i => $l) {
            $texto .= '<text x="200" y="'.($y0 + $i * 28).'" text-anchor="middle" font-size="24" font-weight="600">'.e($l).'</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$c1.'"/><stop offset="1" stop-color="'.$c2.'"/></linearGradient></defs>'
            .'<rect width="400" height="400" fill="url(#g)"/>'
            .'<circle cx="200" cy="140" r="78" fill="#ffffff" fill-opacity="0.14"/>'
            .'<g transform="translate(152 92) scale(4)" fill="none" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'.$icono.'</g>'
            .'<g fill="#ffffff" font-family="Geist, Inter, Segoe UI, Arial, sans-serif">'.$texto
            .'<text x="200" y="372" text-anchor="middle" font-size="16" fill-opacity="0.75" letter-spacing="1">'.e($p->codigo).'</text></g>'
            .'</svg>';
    }

    private function reemplazar(Producto $p, string $ruta): void
    {
        $anterior = $p->imagen;
        $p->forceFill(['imagen' => $ruta])->saveQuietly();
        if ($anterior && $anterior !== $ruta) {
            Storage::disk('public')->delete($anterior);
        }
    }

    private function reducirAWebp(string $ruta): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($ruta));
        if (! $img) {
            return null;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $escala = min(1, self::MAX_LADO / max($w, $h));
        if ($escala < 1) {
            $nueva = imagecreatetruecolor((int) round($w * $escala), (int) round($h * $escala));
            imagealphablending($nueva, false);
            imagesavealpha($nueva, true);
            imagecopyresampled($nueva, $img, 0, 0, 0, 0, imagesx($nueva), imagesy($nueva), $w, $h);
            imagedestroy($img);
            $img = $nueva;
        }
        ob_start();
        imagewebp($img, null, 82);
        imagedestroy($img);

        return ob_get_clean() ?: null;
    }
}
