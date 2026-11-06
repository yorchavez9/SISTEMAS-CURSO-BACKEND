<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Modules\Empresas\Policies\EmpresaPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una instancia por solicitud / job (se reinicia entre solicitudes en Octane y colas).
        $this->app->scoped(\App\Core\Tenancy\EmpresaActual::class);
    }

    public function boot(): void
    {
        Gate::policy(Empresa::class, EmpresaPolicy::class);

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('credenciales', fn (Request $r) => Limit::perMinute(10)->by((string) ($r->user()?->id ?? $r->ip())));
    }
}
