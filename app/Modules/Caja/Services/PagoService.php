<?php

namespace App\Modules\Caja\Services;

use App\Core\Auditoria\Auditor;
use App\Models\CajaMovimiento;
use App\Models\CajaSesion;
use App\Models\Pago;
use App\Models\User;
use App\Models\Venta;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pagos de ventas (al registrar la venta o como cobranza posterior). Cada pago genera un ingreso en la
 * caja abierta del usuario; el saldo de la venta y de sus cuotas se recalcula desde los pagos vigentes.
 */
class PagoService
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  list<array{metodo: string, monto: string|float, monto_recibido?: string|float|null, referencia?: ?string}>  $pagos
     * @param  bool  $exigirTotal  true en ventas al contado: los pagos deben cubrir exactamente el total.
     * @return list<Pago>
     */
    public function registrar(Venta $venta, CajaSesion $sesion, User $user, array $pagos, string $concepto, bool $exigirTotal = false): array
    {
        if ($sesion->estado !== 'abierta' || $sesion->user_id !== $user->id) {
            throw ValidationException::withMessages(['caja' => 'Abre tu caja para registrar pagos.']);
        }

        return DB::transaction(function () use ($venta, $sesion, $user, $pagos, $concepto, $exigirTotal) {
            $venta = Venta::withoutGlobalScopes()->lockForUpdate()->findOrFail($venta->id);
            if ($venta->estado === 'anulada') {
                throw ValidationException::withMessages(['venta' => 'La venta está anulada.']);
            }
            $saldo = Dec::round($venta->saldo);
            $suma = '0';
            foreach ($pagos as $i => $p) {
                if (! isset(Pago::METODOS[$p['metodo']])) {
                    throw ValidationException::withMessages(["pagos.$i.metodo" => 'Método de pago no válido.']);
                }
                if (Dec::cmp($p['monto'], '0') <= 0) {
                    throw ValidationException::withMessages(["pagos.$i.monto" => 'El monto debe ser mayor a cero.']);
                }
                if ($p['metodo'] !== 'efectivo' && ! empty($p['monto_recibido']) && Dec::cmp($p['monto_recibido'], $p['monto']) !== 0) {
                    throw ValidationException::withMessages(["pagos.$i.monto_recibido" => 'Solo el pago en efectivo admite vuelto.']);
                }
                if ($p['metodo'] === 'efectivo' && isset($p['monto_recibido']) && $p['monto_recibido'] !== null && Dec::cmp($p['monto_recibido'], $p['monto']) < 0) {
                    throw ValidationException::withMessages(["pagos.$i.monto_recibido" => 'El efectivo recibido no cubre el monto.']);
                }
                $suma = Dec::add($suma, Dec::round($p['monto']));
            }
            if (Dec::cmp($suma, $saldo) > 0) {
                throw ValidationException::withMessages(['pagos' => "Los pagos ({$suma}) superan el saldo pendiente ({$saldo})."]);
            }
            if ($exigirTotal && Dec::cmp($suma, $saldo) !== 0) {
                throw ValidationException::withMessages(['pagos' => "En una venta al contado los pagos ({$suma}) deben cubrir el total ({$saldo})."]);
            }

            $creados = [];
            foreach ($pagos as $p) {
                $monto = Dec::round($p['monto']);
                $recibido = $p['metodo'] === 'efectivo' && isset($p['monto_recibido']) && $p['monto_recibido'] !== null ? Dec::round($p['monto_recibido']) : $monto;
                $pago = Pago::create([
                    'empresa_id' => $venta->empresa_id, 'venta_id' => $venta->id, 'tipo' => 'pago', 'caja_sesion_id' => $sesion->id, 'metodo' => $p['metodo'],
                    'monto' => $monto, 'monto_recibido' => $p['metodo'] === 'efectivo' ? $recibido : null,
                    'vuelto' => Dec::round(Dec::sub($recibido, $monto)), 'referencia' => $p['referencia'] ?? null, 'user_id' => $user->id,
                ]);
                // A caja entra lo aplicado (el vuelto no queda en caja).
                CajaMovimiento::create([
                    'empresa_id' => $venta->empresa_id, 'caja_sesion_id' => $sesion->id, 'tipo' => 'ingreso', 'concepto' => $concepto,
                    'metodo' => $p['metodo'], 'monto' => $monto, 'descripcion' => $p['referencia'] ?? null,
                    'origen_type' => Pago::class, 'origen_id' => $pago->id, 'user_id' => $user->id,
                ]);
                $creados[] = $pago;
            }
            $this->recalcular($venta);
            $this->auditor->registrar('pagos.registrados', $venta, [], ['concepto' => $concepto, 'monto' => $suma, 'pagos' => count($creados)], $venta->empresa_id);

            return $creados;
        });
    }

    public function anular(Pago $pago, User $user, string $motivo): Pago
    {
        return DB::transaction(function () use ($pago, $user, $motivo) {
            $pago = Pago::withoutGlobalScopes()->lockForUpdate()->findOrFail($pago->id);
            if ($pago->estado === 'anulado') {
                throw ValidationException::withMessages(['pago' => 'El pago ya está anulado.']);
            }
            $sesion = CajaSesion::withoutGlobalScopes()->findOrFail($pago->caja_sesion_id);
            if ($sesion->estado !== 'abierta') {
                throw ValidationException::withMessages(['pago' => 'No se puede anular: la caja de ese pago ya se cerró. Registra una devolución.']);
            }
            $pago->update(['estado' => 'anulado', 'anulado_at' => now(), 'anulado_por' => $user->id, 'motivo_anulacion' => $motivo]);
            CajaMovimiento::create([
                // Anular un pago saca dinero de la caja; anular un reembolso lo devuelve a la caja.
                'empresa_id' => $pago->empresa_id, 'caja_sesion_id' => $sesion->id, 'tipo' => $pago->tipo === 'reembolso' ? 'ingreso' : 'egreso', 'concepto' => 'anulacion_pago',
                'metodo' => $pago->metodo, 'monto' => $pago->monto, 'descripcion' => $motivo,
                'origen_type' => Pago::class, 'origen_id' => $pago->id, 'user_id' => $user->id,
            ]);
            $this->recalcular(Venta::withoutGlobalScopes()->lockForUpdate()->findOrFail($pago->venta_id));
            $this->auditor->registrar('pago.anulado', $pago, ['estado' => 'registrado'], ['estado' => 'anulado', 'motivo' => $motivo], $pago->empresa_id);

            return $pago;
        });
    }

    /**
     * Devuelve dinero al cliente por una nota de crédito: pago tipo "reembolso" + egreso en la caja abierta.
     */
    public function reembolsar(Venta $venta, CajaSesion $sesion, User $user, string $monto, string $metodo, ?int $comprobanteId, ?string $referencia = null): Pago
    {
        if ($sesion->estado !== 'abierta' || $sesion->user_id !== $user->id) {
            throw ValidationException::withMessages(['reembolso' => 'Abre tu caja para registrar el reembolso.']);
        }
        if (! isset(Pago::METODOS[$metodo])) {
            throw ValidationException::withMessages(['reembolso.metodo' => 'Método de pago no válido.']);
        }
        if ($metodo === 'efectivo') {
            $disp = app(CajaService::class)->resumen($sesion)['efectivo_esperado'];
            if (Dec::cmp($monto, $disp) > 0) {
                throw ValidationException::withMessages(['reembolso' => "No hay suficiente efectivo en caja para reembolsar (disponible {$disp})."]);
            }
        }
        $pago = Pago::create([
            'empresa_id' => $venta->empresa_id, 'venta_id' => $venta->id, 'tipo' => 'reembolso', 'comprobante_id' => $comprobanteId,
            'caja_sesion_id' => $sesion->id, 'metodo' => $metodo, 'monto' => Dec::round($monto), 'vuelto' => 0, 'referencia' => $referencia, 'user_id' => $user->id,
        ]);
        CajaMovimiento::create([
            'empresa_id' => $venta->empresa_id, 'caja_sesion_id' => $sesion->id, 'tipo' => 'egreso', 'concepto' => 'reembolso',
            'metodo' => $metodo, 'monto' => Dec::round($monto), 'descripcion' => $comprobanteId ? 'Reembolso por nota de crédito' : 'Reembolso de saldo a favor',
            'origen_type' => Pago::class, 'origen_id' => $pago->id, 'user_id' => $user->id,
        ]);
        $this->auditor->registrar('pago.reembolso', $pago, [], ['venta_id' => $venta->id, 'monto' => Dec::round($monto), 'metodo' => $metodo, 'comprobante_id' => $comprobanteId], $venta->empresa_id);

        return $pago;
    }

    /**
     * Recalcula la venta: neto = total − notas de crédito + notas de débito; pagado = pagos − reembolsos.
     * Reparte lo pagado en las cuotas, de la más antigua a la más reciente.
     */
    public function recalcular(Venta $venta): void
    {
        $venta->refresh();
        $q = fn (string $tipo) => Dec::round(Pago::withoutGlobalScopes()->where('venta_id', $venta->id)->where('estado', 'registrado')->where('tipo', $tipo)->sum('monto'));
        $pagado = Dec::round(Dec::sub($q('pago'), $q('reembolso')));
        // Venta dada de baja ante SUNAT: ya no hay nada que cobrar (lo pagado queda como saldo a favor del cliente).
        $neto = $venta->anulada_por_baja ? '0.00' : Dec::round(Dec::add(Dec::sub($venta->total, $venta->total_notas_credito), $venta->total_notas_debito));
        $saldo = Dec::round(Dec::sub($neto, $pagado));
        $estado = Dec::cmp($saldo, '0') <= 0 ? 'pagado' : (Dec::isZero($pagado) ? 'pendiente' : 'parcial');
        $venta->update(['monto_pagado' => $pagado, 'saldo' => $saldo, 'estado_pago' => $estado]);

        $resto = $pagado;
        foreach ($venta->cuotas()->get() as $c) {
            $aplica = Dec::cmp($resto, '0') <= 0 ? '0.00' : (Dec::cmp($resto, $c->monto) >= 0 ? Dec::round($c->monto) : Dec::round($resto));
            $c->update(['monto_pagado' => $aplica]);
            $resto = Dec::sub($resto, $aplica);
        }
    }
}
