<?php

use App\Integrations\SunatApi\Exceptions\CredencialesNoConfiguradasException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Autenticación SPA por cookie de sesión (Sanctum), sin tokens en el navegador.
        $middleware->statefulApi();
        // Empresa activa por header X-Empresa-Id (+ permiso opcional): ->middleware('empresa:clientes.ver')
        $middleware->alias(['empresa' => \App\Core\Tenancy\ResolveEmpresa::class]);
        // La empresa debe resolverse ANTES del route model binding, para que el global scope
        // impida enlazar registros de otra empresa (p. ej. PUT /series/{serie}).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Core\Tenancy\ResolveEmpresa::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (CredencialesNoConfiguradasException $e) => response()->json(['message' => $e->getMessage()], 409));
    })->create();
