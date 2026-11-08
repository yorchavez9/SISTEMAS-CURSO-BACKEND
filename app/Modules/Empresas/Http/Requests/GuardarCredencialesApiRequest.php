<?php

namespace App\Modules\Empresas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarCredencialesApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'api_key' => is_string($this->api_key) ? trim($this->api_key) : $this->api_key,
            'api_secret' => is_string($this->api_secret) && trim($this->api_secret) !== '' ? trim($this->api_secret) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            // Opcional al actualizar: vacío = conservar el guardado. El servicio lo exige al crear.
            'api_key' => ['nullable', 'string', 'min:8', 'max:255', 'regex:/^\S+$/'],
            // URL base de la API (p. ej. https://sunat.kodevo.es/api/v1). Vacía = la del .env.
            // Solo HTTPS (las credenciales viajan en cabeceras); http solo para localhost en desarrollo.
            'base_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255', function (string $a, mixed $v, \Closure $fail) {
                $u = parse_url((string) $v);
                $local = in_array($u['host'] ?? '', ['localhost', '127.0.0.1'], true);
                if (($u['scheme'] ?? '') !== 'https' && ! $local) {
                    $fail('La URL de la API debe empezar con https://.');
                }
                if (isset($u['query']) || isset($u['fragment'])) {
                    $fail('La URL de la API no debe tener parámetros (?…) ni fragmentos (#…).');
                }
            }],
            // Opcional al actualizar: vacío = conservar el secreto guardado. El servicio exige el secreto al crear.
            'api_secret' => ['nullable', 'string', 'min:8', 'max:255', 'regex:/^\S+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.required' => 'Ingresa el API Key.',
            'api_key.regex' => 'El API Key no debe contener espacios.',
            'api_secret.regex' => 'El API Secret no debe contener espacios.',
        ];
    }
}
