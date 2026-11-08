<?php

namespace App\Modules\Facturacion\Services;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Comprobante;
use App\Models\ComprobanteRetencion;
use App\Models\Comunicacion;
use App\Models\GuiaRemision;
use App\Models\LiquidacionCompra;
use App\Modules\Guias\Services\GuiaService;
use App\Modules\Liquidaciones\Services\LiquidacionService;
use App\Modules\Retenciones\Services\RetencionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comunicación con la API de facturación SIN cola de trabajos.
 *
 * La API (sunat.kodevo.es) ya envía los documentos a SUNAT por su cuenta (enviar_automatico): el sistema solo
 * registra el documento (POST) y luego lee su estado (GET). Por eso no hace falta `queue:work`:
 *  - Emitir: se llama a la API en la misma solicitud, después de confirmar la transacción.
 *  - Estado: se consulta al abrir o listar el documento (la pantalla consulta cada pocos segundos mientras
 *    está pendiente) y, para lo que nadie mira, con `sunat:sincronizar` desde el programador (cron).
 *  - Archivos (XML, CDR, PDF): se traen al descargarlos y, en segundo plano, con `sunat:sincronizar`.
 * Ningún fallo de la API interrumpe la solicitud del usuario: queda registrado en `ultimo_error` y se reintenta.
 */
class ProcesoSunat
{
    /** Segundos mínimos entre dos consultas del mismo documento. */
    public const PAUSA = 3;

    /** Envíos fallidos (sin respuesta de la API) antes de marcar el documento con error. */
    public const MAX_INTENTOS = 8;

    public function __construct(
        private readonly EmisionService $emision,
        private readonly BajaService $bajas,
        private readonly ResumenService $resumenes,
        private readonly GuiaService $guias,
        private readonly RetencionService $retenciones,
        private readonly LiquidacionService $liquidaciones,
    ) {}

    /** Ejecuta $tarea cuando se confirme la transacción en curso (o en el acto si no hay ninguna). */
    public static function alConfirmar(callable $tarea): void
    {
        if (! config('services.sunat_api.envio_inmediato', true)) {
            return; // pruebas automáticas: el envío se invoca explícitamente
        }
        DB::afterCommit($tarea);
    }

    /** Envía el comprobante a la API cuando la transacción que lo creó se confirme. */
    public static function programarComprobante(int $id): void
    {
        self::alConfirmar(fn () => ($c = Comprobante::withoutGlobalScopes()->find($id)) && app(self::class)->emitir($c));
    }

    public static function programarComunicacion(int $id): void
    {
        self::alConfirmar(fn () => ($c = Comunicacion::withoutGlobalScopes()->find($id)) && app(self::class)->enviarComunicacion($c));
    }

    public static function programarGuia(int $id): void
    {
        self::alConfirmar(fn () => ($g = GuiaRemision::withoutGlobalScopes()->find($id)) && app(self::class)->emitirGuia($g));
    }

    // ---------------------------------------------------------------- comprobantes

    public function emitir(Comprobante $c): void
    {
        $lock = Cache::lock("sunat:emitir:comprobante:{$c->id}", 60);
        if (! $lock->get()) {
            return; // otro proceso lo está enviando
        }
        try {
            for ($i = 0; $i < 4; $i++) {
                $c->refresh();
                if ($c->esFinal() || in_array($c->estado, ['error', 'pendiente_resumen'], true) || ($c->api_id && $i > 0)) {
                    return;
                }
                $r = $this->intentar($c, fn () => $this->emision->emitir($c));
                if ($r !== EmisionService::RESULTADO_RENUMERADO) {
                    return; // renumerado: se reintenta en el acto con el nuevo número (máx. 3)
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** Refresca un comprobante pendiente: lo envía si nunca llegó a la API o consulta su estado. */
    public function actualizar(Comprobante $c, bool $forzar = false): void
    {
        if ($c->esFinal() || in_array($c->estado, ['error', 'pendiente_resumen'], true)
            || (! Cache::add("sunat:ver:comprobante:{$c->id}", 1, self::PAUSA) && ! $forzar)) {
            return;
        }
        if (! $c->api_id) {
            if ($c->intentos >= self::MAX_INTENTOS) {
                $c->cambiarEstado('error', 'No se pudo enviar tras varios intentos: '.($c->ultimo_error ?? 'sin respuesta de la API'));

                return;
            }
            $this->emitir($c);

            return;
        }
        $this->intentar($c, fn () => $this->emision->consultarEstado($c));
    }

    /** Descarga a almacenamiento local los archivos que falten (XML, CDR, PDF). */
    public function descargarArchivos(Comprobante $c, ?array $tipos = null): void
    {
        if (! $c->api_id) {
            return;
        }
        $faltan = array_values(array_diff($tipos ?? ['xml', 'cdr', 'pdf'], $c->archivos()->pluck('tipo')->all()));
        if ($faltan) {
            $this->intentar($c, fn () => $this->emision->descargarArchivos($c, $faltan), registrarError: false);
        }
    }

    // ---------------------------------------------------------------- bajas y resúmenes (RA / RC)

    public function enviarComunicacion(Comunicacion $c): void
    {
        $lock = Cache::lock("sunat:emitir:comunicacion:{$c->id}", 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $c->refresh();
            if (! $c->esFinal() && $c->estado !== 'error') {
                $this->intentar($c, fn () => $c->accion === 'envio' ? $this->resumenes->enviar($c) : $this->bajas->enviar($c));
            }
        } finally {
            $lock->release();
        }
    }

    public function actualizarComunicacion(Comunicacion $c, bool $forzar = false): void
    {
        if ($c->esFinal() || $c->estado === 'error' || (! Cache::add("sunat:ver:comunicacion:{$c->id}", 1, self::PAUSA) && ! $forzar)) {
            return;
        }
        // Sin crear en la API, o RC de envío creado pero aún no enviado a SUNAT: se (re)envía.
        if (! $c->api_id || ($c->accion === 'envio' && $c->estado === 'por_enviar')) {
            if (! $c->api_id && $c->intentos >= self::MAX_INTENTOS) {
                $c->update(['estado' => 'error', 'ultimo_error' => mb_substr('No se pudo enviar: '.($c->ultimo_error ?? 'sin respuesta de la API'), 0, 1000)]);

                return;
            }
            $this->enviarComunicacion($c);

            return;
        }
        $this->intentar($c, fn () => $c->accion === 'envio' ? $this->resumenes->consultar($c) : $this->bajas->consultar($c));
    }

    // ---------------------------------------------------------------- guías de remisión

    public function emitirGuia(GuiaRemision $g): void
    {
        $lock = Cache::lock("sunat:emitir:guia:{$g->id}", 60);
        if (! $lock->get()) {
            return;
        }
        try {
            for ($i = 0; $i < 4; $i++) {
                $g->refresh();
                if ($g->esFinal() || $g->estado !== 'por_enviar') {
                    return;
                }
                $r = $this->intentar($g, fn () => $this->guias->emitir($g));
                if ($r !== GuiaService::RESULTADO_RENUMERADO) {
                    return;
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function actualizarGuia(GuiaRemision $g, bool $forzar = false): void
    {
        if ($g->esFinal() || ! in_array($g->estado, ['por_enviar', 'enviado'], true) || (! Cache::add("sunat:ver:guia:{$g->id}", 1, self::PAUSA) && ! $forzar)) {
            return;
        }
        if ($g->estado === 'por_enviar') {
            if ($g->intentos >= self::MAX_INTENTOS) {
                $g->cambiarEstado('error', 'No se pudo enviar tras varios intentos: '.($g->ultimo_error ?? 'sin respuesta de la API'), ['ultimo_error' => $g->ultimo_error]);

                return;
            }
            $this->emitirGuia($g);

            return;
        }
        if ($g->api_id) {
            $this->intentar($g, fn () => $this->guias->consultar($g));
        }
    }

    public function descargarArchivosGuia(GuiaRemision $g, ?array $tipos = null): void
    {
        if (! $g->api_id) {
            return;
        }
        $faltan = array_values(array_diff($tipos ?? ['xml', 'cdr', 'pdf'], array_keys($g->archivos ?? [])));
        if ($faltan) {
            $this->intentar($g, fn () => $this->guias->descargarArchivos($g, $faltan), registrarError: false);
        }
    }

    // ---------------------------------------------------------------- retenciones y percepciones

    public function emitirRetencion(ComprobanteRetencion $c): void
    {
        $lock = Cache::lock("sunat:emitir:retencion:{$c->id}", 60);
        if (! $lock->get()) {
            return;
        }
        try {
            for ($i = 0; $i < 4; $i++) {
                $c->refresh();
                if ($c->esFinal() || $c->estado === 'error' || ($c->api_id && $i > 0)) {
                    return;
                }
                if ($this->intentar($c, fn () => $this->retenciones->emitir($c)) !== RetencionService::RESULTADO_RENUMERADO) {
                    return;
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** Refresca la retención/percepción: la reenvía, consulta su estado o el de su reversión. */
    public function actualizarRetencion(ComprobanteRetencion $c, bool $forzar = false): void
    {
        if (! Cache::add("sunat:ver:retencion:{$c->id}", 1, self::PAUSA) && ! $forzar) {
            return;
        }
        if ($c->reversion_estado === 'enviado') {
            $this->intentar($c, fn () => $this->retenciones->consultarReversion($c), registrarError: false);

            return;
        }
        if ($c->esFinal() || $c->estado === 'error') {
            return;
        }
        if (! $c->api_id) {
            if ($c->intentos >= self::MAX_INTENTOS) {
                $c->cambiarEstado('error', 'No se pudo enviar tras varios intentos: '.($c->ultimo_error ?? 'sin respuesta de la API'));

                return;
            }
            $this->emitirRetencion($c);

            return;
        }
        $this->intentar($c, fn () => $this->retenciones->consultar($c));
    }

    // ---------------------------------------------------------------- liquidaciones de compra

    public function emitirLiquidacion(LiquidacionCompra $c): void
    {
        $lock = Cache::lock("sunat:emitir:liquidacion:{$c->id}", 60);
        if (! $lock->get()) {
            return;
        }
        try {
            for ($i = 0; $i < 4; $i++) {
                $c->refresh();
                if ($c->esFinal() || $c->estado === 'error' || ($c->api_id && $i > 0)) {
                    return;
                }
                if ($this->intentar($c, fn () => $this->liquidaciones->emitir($c)) !== LiquidacionService::RESULTADO_RENUMERADO) {
                    return;
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function actualizarLiquidacion(LiquidacionCompra $c, bool $forzar = false): void
    {
        if (! Cache::add("sunat:ver:liquidacion:{$c->id}", 1, self::PAUSA) && ! $forzar) {
            return;
        }
        if ($c->reversion_estado === 'enviado') {
            $this->intentar($c, fn () => $this->liquidaciones->consultarReversion($c), registrarError: false);

            return;
        }
        if ($c->esFinal() || $c->estado === 'error') {
            return;
        }
        if (! $c->api_id) {
            if ($c->intentos >= self::MAX_INTENTOS) {
                $c->cambiarEstado('error', 'No se pudo enviar tras varios intentos: '.($c->ultimo_error ?? 'sin respuesta de la API'));

                return;
            }
            $this->emitirLiquidacion($c);

            return;
        }
        $this->intentar($c, fn () => $this->liquidaciones->consultar($c));
    }

    // ---------------------------------------------------------------- pendientes de una empresa

    /**
     * Refresca lo pendiente de una empresa (listados y `sunat:sincronizar`). Acotado para no exceder el
     * límite de la API (25 solicitudes por minuto por empresa) ni demorar la pantalla.
     */
    public function actualizarPendientes(int $empresaId, int $max = 5, bool $conArchivos = false): void
    {
        if (! Cache::add("sunat:pendientes:$empresaId", 1, 10)) {
            return;
        }
        $hace = now()->subSeconds(5);
        Comprobante::deEmpresa($empresaId)->whereIn('estado', ['por_enviar', 'enviado'])->where('updated_at', '<', $hace)
            ->orderBy('updated_at')->limit($max)->get()->each(fn ($c) => $this->actualizar($c));
        Comunicacion::deEmpresa($empresaId)->whereIn('estado', ['por_enviar', 'enviado'])->where('updated_at', '<', $hace)
            ->orderBy('updated_at')->limit($max)->get()->each(fn ($c) => $this->actualizarComunicacion($c));
        GuiaRemision::deEmpresa($empresaId)->whereIn('estado', ['por_enviar', 'enviado'])->where('updated_at', '<', $hace)
            ->orderBy('updated_at')->limit($max)->get()->each(fn ($g) => $this->actualizarGuia($g));
        ComprobanteRetencion::deEmpresa($empresaId)->where('updated_at', '<', $hace)
            ->where(fn ($q) => $q->whereIn('estado', ['por_enviar', 'enviado'])->orWhere('reversion_estado', 'enviado'))
            ->orderBy('updated_at')->limit($max)->get()->each(fn ($c) => $this->actualizarRetencion($c));
        LiquidacionCompra::deEmpresa($empresaId)->where('updated_at', '<', $hace)
            ->where(fn ($q) => $q->whereIn('estado', ['por_enviar', 'enviado'])->orWhere('reversion_estado', 'enviado'))
            ->orderBy('updated_at')->limit($max)->get()->each(fn ($c) => $this->actualizarLiquidacion($c));

        if ($conArchivos) {
            // Respaldo local de XML/CDR/PDF de lo ya aceptado (lo que nadie descargó todavía).
            Comprobante::deEmpresa($empresaId)->whereIn('estado', ['aceptado', 'observado'])->whereNotNull('api_id')
                ->whereDoesntHave('archivos', fn ($q) => $q->where('tipo', 'cdr'))
                ->orderBy('id')->limit($max)->get()->each(fn ($c) => $this->descargarArchivos($c));
            GuiaRemision::deEmpresa($empresaId)->where('estado', 'aceptado')->whereNotNull('api_id')
                ->orderByDesc('id')->limit(20)->get()
                ->filter(fn ($g) => count($g->archivos ?? []) < 3)->take($max)->each(fn ($g) => $this->descargarArchivosGuia($g));
        }
    }

    /**
     * Ejecuta una llamada a la API sin dejar que su fallo interrumpa al usuario: límite de solicitudes,
     * conexión caída o credenciales faltantes quedan en `ultimo_error` y se reintentan al volver a consultar.
     */
    private function intentar(Comprobante|Comunicacion|GuiaRemision|ComprobanteRetencion|LiquidacionCompra $doc, callable $llamada, bool $registrarError = true): mixed
    {
        try {
            return $llamada();
        } catch (SunatApiRateLimitException) {
            $mensaje = 'La API de facturación alcanzó el límite de solicitudes por minuto; se reintentará en unos segundos.';
        } catch (Throwable $e) {
            report($e);
            $mensaje = 'No se pudo comunicar con la API de facturación: '.$e->getMessage();
        }
        if ($registrarError) {
            $doc->forceFill(['ultimo_error' => mb_substr($mensaje, 0, 1000)])->save();
        }

        return null;
    }
}
