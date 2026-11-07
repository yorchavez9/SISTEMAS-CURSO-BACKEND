<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resumen diario de boletas (modo "resumen"): cada hora intenta enviar las fechas anteriores pendientes.
// Requiere `php artisan schedule:work` (desarrollo) o el cron `* * * * * php artisan schedule:run` (producción).
Illuminate\Support\Facades\Schedule::command('sunat:enviar-resumenes')->hourlyAt(15)->timezone('America/Lima')->withoutOverlapping();

// Sin cola de trabajos: la API de facturación envía a SUNAT por su cuenta; cada minuto se reenvía lo que no llegó
// a la API, se consultan los estados pendientes y se guardan XML/CDR/PDF. Las pantallas también actualizan al consultar.
Illuminate\Support\Facades\Schedule::command('sunat:sincronizar')->everyMinute()->withoutOverlapping(5);
