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
            'api_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/^\S+$/'],
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
