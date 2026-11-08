<?php

namespace App\Modules\Empresas\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Modules\Empresas\Services\PerfilApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Configuración completa de la empresa en la API de facturación: datos que salen en el XML y el PDF, logo,
 * certificado digital, Clave SOL / credenciales GRE, mensajes y medios de pago del PDF y régimen tributario.
 * Reglas de validación tomadas de la especificación OpenAPI de la API (PUT /empresa).
 */
class PerfilApiController extends Controller
{
    public function __construct(private readonly PerfilApiService $svc, private readonly Auditor $auditor) {}

    public function show(Empresa $empresa): JsonResponse
    {
        Gate::authorize('view', $empresa);

        return response()->json(['data' => $this->svc->leer($empresa)]);
    }

    public function update(Request $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);
        $d = $request->validate([
            'razon_social' => ['sometimes', 'required', 'string', 'max:255'],
            'nombre_comercial' => ['sometimes', 'nullable', 'string', 'max:255'],
            'direccion' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ubigeo' => ['sometimes', 'nullable', 'regex:/^\d{6}$/'],
            'departamento' => ['sometimes', 'nullable', 'string', 'max:100'],
            'provincia' => ['sometimes', 'nullable', 'string', 'max:100'],
            'distrito' => ['sometimes', 'nullable', 'string', 'max:100'],
            'telefonos' => ['sometimes', 'array', 'max:5'],
            'telefonos.*' => ['string', 'max:20'],
            'emails' => ['sometimes', 'array', 'max:5'],
            'emails.*' => ['email', 'max:100'],
            'cuentas_bancarias' => ['sometimes', 'array', 'max:5'],
            'cuentas_bancarias.*.banco' => ['required', 'string', 'max:50'],
            'cuentas_bancarias.*.tipo_cuenta' => ['required', 'string', 'max:30'],
            'cuentas_bancarias.*.moneda' => ['required', 'in:PEN,USD'],
            'cuentas_bancarias.*.numero' => ['required', 'string', 'max:30'],
            'cuentas_bancarias.*.cci' => ['nullable', 'string', 'max:25'],
            'cuentas_bancarias.*.titular' => ['nullable', 'string', 'max:100'],
            'billeteras_digitales' => ['sometimes', 'array', 'max:5'],
            'billeteras_digitales.*.tipo' => ['required', 'in:yape,plin,tunki,otro'],
            'billeteras_digitales.*.numero' => ['required', 'string', 'max:20'],
            'billeteras_digitales.*.titular' => ['nullable', 'string', 'max:100'],
            'mensaje_agradecimiento' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mensaje_promocional' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sol_user' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sol_pass' => ['sometimes', 'nullable', 'string', 'max:50'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_regime' => ['sometimes', Rule::in(['general', 'rer', 'mype', 'rus'])],
            'nrus_categoria' => ['nullable', Rule::requiredIf(fn () => $request->input('tax_regime') === 'rus'), Rule::in([1, 2])],
            'igv_rate_override' => ['sometimes', 'nullable', 'numeric', 'between:0,30'],
        ], [], [
            'cuentas_bancarias.*.banco' => 'banco', 'cuentas_bancarias.*.tipo_cuenta' => 'tipo de cuenta', 'cuentas_bancarias.*.numero' => 'número de cuenta',
            'cuentas_bancarias.*.cci' => 'CCI', 'billeteras_digitales.*.numero' => 'número de la billetera', 'emails.*' => 'correo', 'telefonos.*' => 'teléfono',
            'sol_user' => 'usuario SOL', 'sol_pass' => 'clave SOL', 'tax_regime' => 'régimen', 'nrus_categoria' => 'categoría del Nuevo RUS',
            'igv_rate_override' => 'tasa de IGV manual',
        ]);
        $d = array_intersect_key($d, array_flip(PerfilApiService::CAMPOS));
        $perfil = $this->svc->actualizar($empresa, $d);
        // Auditoría sin secretos: solo qué se cambió.
        $this->auditor->registrar('empresa.perfil_api', $empresa, [], [
            'campos' => array_values(array_diff(array_keys($d), ['sol_pass', 'client_secret'])),
            'clave_sol_cambiada' => ! empty($d['sol_pass']), 'client_secret_cambiado' => ! empty($d['client_secret']),
        ], $empresa->id);

        return response()->json(['data' => $perfil, 'message' => 'Configuración guardada en la API de facturación.']);
    }

    public function logo(Request $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048']], [], ['logo' => 'logo']);
        $perfil = $this->svc->subirLogo($empresa, $request->file('logo'));
        $this->auditor->registrar('empresa.logo', $empresa, [], ['archivo' => $request->file('logo')->getClientOriginalName()], $empresa->id);

        return response()->json(['data' => $perfil, 'message' => 'Logo actualizado. Aparecerá en los PDF de los comprobantes.']);
    }

    public function certificado(Request $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);
        $request->validate([
            'certificado' => ['required', 'file', 'max:2048', function (string $a, $f, \Closure $fail) {
                if (! in_array(strtolower($f->getClientOriginalExtension()), ['pfx', 'p12'], true)) {
                    $fail('El certificado debe ser un archivo .pfx o .p12.');
                }
            }],
            'contrasena_certificado' => ['required', 'string', 'max:100'],
        ], [], ['certificado' => 'certificado', 'contrasena_certificado' => 'contraseña del certificado']);
        $perfil = $this->svc->subirCertificado($empresa, $request->file('certificado'), $request->string('contrasena_certificado'));
        $this->auditor->registrar('empresa.certificado', $empresa, [], ['archivo' => $request->file('certificado')->getClientOriginalName()], $empresa->id);

        return response()->json(['data' => $perfil, 'message' => 'Certificado digital actualizado en la API de facturación.']);
    }
}
