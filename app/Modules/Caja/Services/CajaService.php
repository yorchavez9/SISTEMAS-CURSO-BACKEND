<?php

namespace App\Modules\Caja\Services;

use App\Core\Auditoria\Auditor;
use App\Models\Caja;
use App\Models\CajaMovimiento;
use App\Models\CajaSesion;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Pago;
use App\Models\User;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CajaService
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Cajas de la empresa; crea "Caja principal" en cada establecimiento que no tenga ninguna. */
    public function cajas(Empresa $empresa)
    {
        foreach (Establecimiento::deEmpresa($empresa->id)->where('activo', true)->get() as $est) {
            if (! Caja::deEmpresa($empresa->id)->where('establecimiento_id', $est->id)->exists()) {
                Caja::create(['empresa_id' => $empresa->id, 'establecimiento_id' => $est->id, 'nombre' => 'Caja principal']);
            }
        }

        return Caja::deEmpresa($empresa->id)->with(['establecimiento:id,nombre,cod_local', 'sesionAbierta.usuario:id,name'])->where('activo', true)->orderBy('id')->get();
    }

    public function sesionDe(Empresa $empresa, User $user): ?CajaSesion
    {
        return CajaSesion::deEmpresa($empresa->id)->where('user_id', $user->id)->where('estado', 'abierta')->with('caja.establecimiento')->first();
    }

    public function abrir(Empresa $empresa, User $user, int $cajaId, string $monto): CajaSesion
    {
        if ($this->sesionDe($empresa, $user)) {
            throw ValidationException::withMessages(['caja_id' => 'Ya tienes una caja abierta. Ciérrala antes de abrir otra.']);
        }
        $caja = Caja::deEmpresa($empresa->id)->where('activo', true)->find($cajaId)
            ?? throw ValidationException::withMessages(['caja_id' => 'Caja no encontrada.']);

        try {
            $sesion = DB::transaction(function () use ($empresa, $user, $caja, $monto) {
                $s = CajaSesion::create([
                    'empresa_id' => $empresa->id, 'caja_id' => $caja->id, 'user_id' => $user->id,
                    'apertura_at' => now(), 'monto_apertura' => Dec::round($monto), 'estado' => 'abierta', 'abierta_flag' => true,
                ]);
                CajaMovimiento::create([
                    'empresa_id' => $empresa->id, 'caja_sesion_id' => $s->id, 'tipo' => 'ingreso', 'concepto' => 'apertura',
                    'metodo' => 'efectivo', 'monto' => Dec::round($monto), 'descripcion' => 'Fondo de apertura', 'user_id' => $user->id,
                ]);

                return $s;
            });
        } catch (QueryException $e) {
            // Índice único (caja_id, abierta_flag): otra persona abrió esta caja al mismo tiempo.
            throw ValidationException::withMessages(['caja_id' => 'Esta caja ya está abierta por otro usuario.']);
        }
        $this->auditor->registrar('caja.abierta', $sesion, [], ['caja' => $caja->nombre, 'monto_apertura' => $sesion->monto_apertura], $empresa->id);

        return $sesion->load('caja.establecimiento');
    }

    public function movimiento(CajaSesion $s, User $user, array $d): CajaMovimiento
    {
        $this->exigirAbierta($s);
        if ($d['tipo'] === 'egreso' && $d['metodo'] === 'efectivo') {
            $disp = $this->resumen($s)['efectivo_esperado'];
            if (Dec::cmp($d['monto'], $disp) > 0) {
                throw ValidationException::withMessages(['monto' => "No hay suficiente efectivo en caja (disponible {$disp})."]);
            }
        }
        $m = CajaMovimiento::create([
            'empresa_id' => $s->empresa_id, 'caja_sesion_id' => $s->id, 'tipo' => $d['tipo'], 'concepto' => $d['concepto'],
            'metodo' => $d['metodo'], 'monto' => Dec::round($d['monto']), 'descripcion' => $d['descripcion'] ?? null, 'user_id' => $user->id,
        ]);
        $this->auditor->registrar('caja.movimiento', $m, [], $m->only(['tipo', 'concepto', 'metodo', 'monto', 'descripcion']), $s->empresa_id);

        return $m;
    }

    /** Totales por método y efectivo esperado (fondo + ingresos en efectivo − egresos en efectivo). */
    public function resumen(CajaSesion $s): array
    {
        $porMetodo = [];
        foreach (CajaMovimiento::withoutGlobalScopes()->where('caja_sesion_id', $s->id)->get() as $m) {
            $porMetodo[$m->metodo] ??= ['ingresos' => '0', 'egresos' => '0'];
            $k = $m->tipo === 'ingreso' ? 'ingresos' : 'egresos';
            $porMetodo[$m->metodo][$k] = Dec::add($porMetodo[$m->metodo][$k], $m->monto);
        }
        $out = [];
        foreach ($porMetodo as $met => $v) {
            $out[$met] = ['metodo' => $met, 'label' => Pago::METODOS[$met] ?? $met, 'ingresos' => Dec::round($v['ingresos']), 'egresos' => Dec::round($v['egresos']), 'neto' => Dec::round(Dec::sub($v['ingresos'], $v['egresos']))];
        }
        $esperado = $out['efectivo']['neto'] ?? '0.00';
        $ventas = Pago::withoutGlobalScopes()->where('caja_sesion_id', $s->id)->where('estado', 'registrado')->count();

        return ['por_metodo' => array_values($out), 'efectivo_esperado' => Dec::round($esperado), 'pagos_registrados' => $ventas];
    }

    public function cerrar(CajaSesion $s, User $user, string $contado, ?string $observacion): CajaSesion
    {
        return DB::transaction(function () use ($s, $user, $contado, $observacion) {
            $s = CajaSesion::withoutGlobalScopes()->lockForUpdate()->findOrFail($s->id);
            $this->exigirAbierta($s);
            $r = $this->resumen($s);
            $s->update([
                'estado' => 'cerrada', 'abierta_flag' => null, 'cierre_at' => now(), 'cerrado_por' => $user->id,
                'efectivo_esperado' => $r['efectivo_esperado'], 'efectivo_contado' => Dec::round($contado),
                'diferencia' => Dec::round(Dec::sub($contado, $r['efectivo_esperado'])), 'resumen_cierre' => $r, 'observacion' => $observacion,
            ]);
            $this->auditor->registrar('caja.cerrada', $s, [], $s->only(['efectivo_esperado', 'efectivo_contado', 'diferencia']), $s->empresa_id);

            return $s;
        });
    }

    public function exigirAbierta(CajaSesion $s): void
    {
        if ($s->estado !== 'abierta') {
            throw ValidationException::withMessages(['caja' => 'La caja está cerrada.']);
        }
    }
}
