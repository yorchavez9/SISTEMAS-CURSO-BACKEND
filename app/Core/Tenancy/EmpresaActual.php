<?php

namespace App\Core\Tenancy;

use App\Models\Empresa;
use RuntimeException;

/**
 * Empresa activa de la solicitud (resuelta por el middleware ResolveEmpresa a partir de X-Empresa-Id).
 * Se registra como singleton con alcance de solicitud (scoped).
 */
class EmpresaActual
{
    private ?Empresa $empresa = null;

    public function establecer(Empresa $empresa): void
    {
        $this->empresa = $empresa;
        setPermissionsTeamId($empresa->id);
    }

    public function get(): Empresa
    {
        return $this->empresa ?? throw new RuntimeException('No hay empresa activa en esta solicitud.');
    }

    public function id(): ?int
    {
        return $this->empresa?->id;
    }

    public function limpiar(): void
    {
        $this->empresa = null;
    }
}
