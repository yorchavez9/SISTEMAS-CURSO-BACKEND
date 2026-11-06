<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resumen diario de boletas (modo "resumen"): cada hora intenta enviar las fechas anteriores pendientes.
// Requiere `php artisan schedule:work` (desarrollo) o el cron `* * * * * php artisan schedule:run` (producción).
Illuminate\Support\Facades\Schedule::command('sunat:enviar-resumenes')->hourlyAt(15)->timezone('America/Lima')->withoutOverlapping();
