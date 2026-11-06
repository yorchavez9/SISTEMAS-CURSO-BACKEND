<?php

namespace App\Core\Auditoria;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría. Los valores sensibles deben llegar ya redactados.
 */
class Auditor
{
    public function registrar(string $evento, ?Model $modelo = null, array $antes = [], array $despues = [], ?int $empresaId = null): void
    {
        $request = request();
        AuditLog::create([
            'empresa_id' => $empresaId ?? ($modelo?->getAttribute('empresa_id')),
            'user_id' => $request?->user()?->id,
            'evento' => $evento,
            'auditable_type' => $modelo ? $modelo::class : null,
            'auditable_id' => $modelo?->getKey(),
            'old_values' => $antes ?: null,
            'new_values' => $despues ?: null,
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
        ]);
    }
}
