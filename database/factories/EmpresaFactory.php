<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Empresa> */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    public function definition(): array
    {
        return [
            'ruc' => '20'.fake()->unique()->numerify('#########'),
            'razon_social' => strtoupper(fake()->company()).' S.A.C.',
            'direccion' => 'AV. PRINCIPAL 123',
            'ubigeo' => '150101',
            'entorno_activo' => 'pruebas',
        ];
    }
}
