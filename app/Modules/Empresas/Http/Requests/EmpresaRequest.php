<?php

namespace App\Modules\Empresas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se resuelve en el controlador (policy)
    }

    public function rules(): array
    {
        $empresa = $this->route('empresa');
        $creando = $empresa === null;

        return [
            'ruc' => [$creando ? 'required' : 'sometimes', 'string', Rule::unique('empresas', 'ruc')->ignore($empresa?->id),
                function (string $attr, mixed $v, \Closure $fail) {
                    if ($err = \App\Modules\Clientes\DocumentoIdentidad::errorRuc((string) $v)) {
                        $fail($err);
                    }
                }],
            'razon_social' => [$creando ? 'required' : 'sometimes', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'departamento' => ['nullable', 'string', 'max:60'],
            'provincia' => ['nullable', 'string', 'max:60'],
            'distrito' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'entorno_activo' => ['sometimes', Rule::in(['pruebas', 'produccion'])],
            'activo' => ['sometimes', 'boolean'],
            'permitir_stock_negativo' => ['sometimes', 'boolean'],
            'envio_boletas' => ['sometimes', 'in:individual,resumen'],
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.unique' => 'Ya existe una empresa registrada con este RUC.',
            'ubigeo.regex' => 'El ubigeo debe tener 6 dígitos.',
        ];
    }
}
