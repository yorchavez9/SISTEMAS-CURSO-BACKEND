<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_superadmin',
        'activo',
    ];

    protected $attributes = [
        'is_superadmin' => false,
        'activo' => true,
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class)->withPivot('activo')->withTimestamps();
    }

    public function perteneceA(Empresa $empresa): bool
    {
        return $this->is_superadmin
            || $this->empresas()->whereKey($empresa->getKey())->wherePivot('activo', true)->exists();
    }

    /** Fija el contexto de permisos (spatie team) en la empresa indicada. */
    public function enEmpresa(Empresa $empresa): static
    {
        setPermissionsTeamId($empresa->getKey());
        $this->unsetRelation('roles')->unsetRelation('permissions');

        return $this;
    }

    public function puede(Empresa $empresa, string $permiso): bool
    {
        if (! $this->activo) {
            return false;
        }
        if ($this->is_superadmin) {
            return true;
        }
        if (! $this->perteneceA($empresa)) {
            return false;
        }

        return $this->enEmpresa($empresa)->checkPermissionTo($permiso);
    }

    /** @return list<string> */
    public function rolesEn(Empresa $empresa): array
    {
        return $this->enEmpresa($empresa)->getRoleNames()->values()->all();
    }

    /** @return list<string> */
    public function permisosEn(Empresa $empresa): array
    {
        if ($this->is_superadmin) {
            return \App\Core\Permisos\Permisos::todos();
        }

        return $this->enEmpresa($empresa)->getAllPermissions()->pluck('name')->values()->all();
    }

    /** Agrega el usuario a la empresa con un rol (reemplaza el rol que tuviera en esa empresa). */
    public function asignarEnEmpresa(Empresa $empresa, string $rol): void
    {
        $this->empresas()->syncWithoutDetaching([$empresa->getKey() => ['activo' => true]]);
        $this->enEmpresa($empresa)->syncRoles([$rol]);
        $this->unsetRelation('roles');
    }
}
