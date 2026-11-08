<?php

namespace App\Modules\Empresas\Services;

use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\Empresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Perfil de la empresa en la API de facturación (GET/PUT /empresa, POST /empresa/logo, POST /empresa/certificado),
 * según la especificación OpenAPI publicada en https://sunat.kodevo.es/docs.
 *
 * Es lo que aparece en el XML y en los PDF de los comprobantes: razón social, dirección, logo, teléfonos, correos,
 * cuentas bancarias, billeteras, mensajes, además del certificado digital, la Clave SOL, las credenciales GRE y el
 * régimen tributario. Se trabaja sobre la cuenta del ENTORNO ACTIVO de la empresa.
 *
 * Seguridad: la contraseña del certificado, la Clave SOL y el client_secret solo se reenvían a la API; nunca se guardan
 * en el sistema, nunca se devuelven al navegador y no se registran en el historial de llamadas (se enmascaran).
 */
class PerfilApiService
{
    /** Campos editables (los mismos que acepta PUT /empresa). */
    public const CAMPOS = [
        'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito',
        'telefonos', 'emails', 'cuentas_bancarias', 'billeteras_digitales', 'mensaje_agradecimiento', 'mensaje_promocional',
        'sol_user', 'sol_pass', 'client_id', 'client_secret', 'tax_regime', 'nrus_categoria', 'igv_rate_override',
    ];

    private const SECRETOS = ['sol_pass', 'client_secret', 'contrasena_certificado'];

    public function __construct(private readonly SunatApiClientFactory $factory) {}

    public function leer(Empresa $empresa): array
    {
        $res = $this->api($empresa)->get('/empresa');
        $this->exigirOk($res, 'consultar el perfil');
        $d = (array) ($res->datos() ?? []);
        $logo = $d['logo_url'] ?? null;

        return [
            'entorno_sistema' => $empresa->entorno_activo,
            'entorno_api' => $d['entorno'] ?? null,
            'ruc' => $d['ruc'] ?? null,
            'razon_social' => $d['razon_social'] ?? null,
            'nombre_comercial' => $d['nombre_comercial'] ?? null,
            'direccion' => $d['direccion'] ?? null,
            'ubigeo' => $d['ubigeo'] ?? null,
            'departamento' => $d['departamento'] ?? null,
            'provincia' => $d['provincia'] ?? null,
            'distrito' => $d['distrito'] ?? null,
            'telefonos' => array_values((array) ($d['telefonos'] ?? [])),
            'emails' => array_values((array) ($d['emails'] ?? [])),
            'cuentas_bancarias' => array_values((array) ($d['cuentas_bancarias'] ?? [])),
            'billeteras_digitales' => array_values((array) ($d['billeteras_digitales'] ?? [])),
            'mensaje_agradecimiento' => $d['mensaje_agradecimiento'] ?? null,
            'mensaje_promocional' => $d['mensaje_promocional'] ?? null,
            // La API devuelve una ruta relativa a su propio dominio (p. ej. /storage/logos/...).
            'logo_url' => $logo ? (str_starts_with($logo, 'http') ? $logo : $this->origenApi().$logo) : null,
            'tiene_certificado' => (bool) ($d['tiene_certificado'] ?? false),
            'sol_user' => $d['sol_user'] ?? null,
            'tiene_sol_pass' => (bool) ($d['tiene_sol_pass'] ?? false),
            'client_id' => $d['client_id'] ?? null,
            'tiene_client_secret' => (bool) ($d['tiene_client_secret'] ?? false),
            'tax_regime' => $d['tax_regime'] ?? null,
            'nrus_categoria' => isset($d['nrus_categoria']) ? (int) $d['nrus_categoria'] : null,
            'igv_rate_override' => isset($d['igv_rate_override']) ? (float) $d['igv_rate_override'] : null,
            'tasa_igv_vigente' => isset($d['tasa_igv_vigente']) ? (float) $d['tasa_igv_vigente'] : null,
            'plan' => $d['plan'] ?? null,
            'documentos_este_mes' => $d['documentos_este_mes'] ?? null,
            'max_documentos_mes' => $d['max_documentos_mes'] ?? null,
        ];
    }

    /** Actualiza el perfil en la API y sincroniza los datos que el sistema también guarda localmente. */
    public function actualizar(Empresa $empresa, array $datos): array
    {
        // Los secretos vacíos significan "no cambiar": no se envían.
        foreach (['sol_pass', 'client_secret'] as $k) {
            if (array_key_exists($k, $datos) && ($datos[$k] === null || $datos[$k] === '')) {
                unset($datos[$k]);
            }
        }
        $res = $this->api($empresa)->put('/empresa', $datos);
        $this->registrar($empresa, 'perfil_actualizar', 'PUT', '/empresa', $datos, $res);
        $this->exigirOk($res, 'actualizar el perfil');

        $local = array_filter([
            'razon_social' => $datos['razon_social'] ?? null, 'nombre_comercial' => $datos['nombre_comercial'] ?? null,
            'direccion' => $datos['direccion'] ?? null, 'ubigeo' => $datos['ubigeo'] ?? null, 'departamento' => $datos['departamento'] ?? null,
            'provincia' => $datos['provincia'] ?? null, 'distrito' => $datos['distrito'] ?? null,
            'telefono' => array_key_exists('telefonos', $datos) ? ($datos['telefonos'][0] ?? null) : null,
            'email' => array_key_exists('emails', $datos) ? ($datos['emails'][0] ?? null) : null,
        ], fn ($v) => $v !== null);
        if ($local) {
            $empresa->update($local);
        }

        $perfil = $this->leer($empresa);
        if (array_intersect(array_keys($datos), ['tax_regime', 'nrus_categoria', 'igv_rate_override'])) {
            // El cálculo local usa la tasa vigente de la API (credencial.remoto): se actualiza en el acto.
            $cred = $empresa->credencialApi();
            $cred?->forceFill(['remoto' => array_merge($cred->remoto ?? [], [
                'tax_regime' => $perfil['tax_regime'], 'tasa_igv_vigente' => $perfil['tasa_igv_vigente'],
            ])])->save();
        }

        return $perfil;
    }

    public function subirLogo(Empresa $empresa, UploadedFile $logo): array
    {
        $res = $this->api($empresa)->postMultipart('/empresa/logo', [
            ['name' => 'logo', 'contents' => (string) file_get_contents($logo->getRealPath()), 'filename' => 'logo.'.($logo->guessExtension() ?: 'png')],
        ]);
        $this->registrar($empresa, 'perfil_logo', 'POST', '/empresa/logo', ['logo' => $logo->getClientOriginalName()], $res);
        $this->exigirOk($res, 'subir el logo', 'logo');

        return $this->leer($empresa);
    }

    /** Reemplaza el certificado digital (.pfx/.p12) en la API. El archivo y su contraseña no se guardan en el sistema. */
    public function subirCertificado(Empresa $empresa, UploadedFile $certificado, string $contrasena): array
    {
        $res = $this->api($empresa)->postMultipart('/empresa/certificado', [
            ['name' => 'certificado', 'contents' => (string) file_get_contents($certificado->getRealPath()), 'filename' => 'certificado.'.strtolower($certificado->getClientOriginalExtension() ?: 'pfx')],
            ['name' => 'contrasena_certificado', 'contents' => $contrasena],
        ]);
        $this->registrar($empresa, 'perfil_certificado', 'POST', '/empresa/certificado', ['certificado' => $certificado->getClientOriginalName(), 'contrasena_certificado' => $contrasena], $res);
        $this->exigirOk($res, 'actualizar el certificado', 'certificado');

        return $this->leer($empresa);
    }

    private ?Empresa $empresaActual = null;

    private function api(Empresa $empresa): SunatApiClient
    {
        $this->empresaActual = $empresa; // para armar la URL absoluta del logo con la URL base de sus credenciales

        return $this->factory->paraEmpresa($empresa);
    }

    private function origenApi(): string
    {
        $u = parse_url((string) ($this->empresaActual?->credencialApi()?->base_url ?: config('services.sunat_api.base_url')));

        return ($u['scheme'] ?? 'https').'://'.($u['host'] ?? '').(isset($u['port']) ? ':'.$u['port'] : '');
    }

    /** Traduce los errores de la API a errores de validación del formulario. */
    private function exigirOk(SunatApiResponse $res, string $accion, string $campo = 'perfil'): void
    {
        if ($res->ok()) {
            return;
        }
        $errores = $res->errores();
        if (is_array($errores) && $errores && ! array_is_list($errores)) {
            throw ValidationException::withMessages(array_map(fn ($m) => (array) $m, $errores));
        }
        $detalle = is_array($errores) && $errores ? ': '.collect($errores)->flatten()->implode(' ') : '';
        throw ValidationException::withMessages([$campo => "La API no permitió $accion: ".($res->mensaje() ?? "HTTP {$res->status}").$detalle]);
    }

    private function registrar(Empresa $empresa, string $op, string $metodo, string $endpoint, array $payload, SunatApiResponse $res): void
    {
        foreach (self::SECRETOS as $k) {
            if (array_key_exists($k, $payload)) {
                $payload[$k] = '***';
            }
        }
        ApiSolicitud::create([
            'empresa_id' => $empresa->id, 'comprobante_id' => null, 'operacion' => $op, 'metodo' => $metodo, 'endpoint' => $endpoint,
            'payload' => $payload, 'http_status' => $res->status, 'respuesta' => $res->json, 'duracion_ms' => $res->durationMs,
        ]);
    }
}
