<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'given_names', 'paternal_surname', 'maternal_surname', 'phone', 'address', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token', 'failed_login_attempts', 'locked_until'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /** Iguales a los defaults de las columnas, para que un usuario recién creado no los tenga en null. */
    protected $attributes = ['is_active' => true, 'failed_login_attempts' => 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** El correo se guarda en minúsculas; es único en todo el sistema. */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Panel que ve el usuario al entrar, según su rol de mayor jerarquía.
     */
    public function panel(): ?string
    {
        foreach (Roles::PANEL_PRIORITY as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Comunidad a la que pertenece. Se asigna en el servidor (nunca es fillable);
     * es null solo para el superadministrador.
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Roles::SUPERADMIN);
    }
}
