<?php

namespace App\Users;

use App\Auth\Assignments;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Permissions;
use App\Models\Roles;
use App\Models\User;
use App\Support\PersonData;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Gestión de usuarios de la comunidad actual (CDU25 a CDU28). La usan la API REST y la
 * sincronización del escritorio, así ambas aplican las mismas reglas.
 */
class UserService
{
    /** Hash bcrypt generado en el equipo (para altas sin conexión, sin guardar la contraseña). */
    public const BCRYPT_PATTERN = '/^\$2y\$(1[0-4])\$[.\/A-Za-z0-9]{53}$/';

    public function __construct(
        private readonly CurrentCommunity $context,
        private readonly Assignments $assignments,
    ) {}

    public function find(string $id): User
    {
        $user = User::where('community_id', $this->communityId())->find($id);

        return $user ?? throw new ApiException('not_found', 'Usuario no encontrado.', 404);
    }

    /**
     * CDU25: crea la cuenta activa con el rol indicado.
     */
    public function create(User $actor, array $data, ?string $id = null): User
    {
        $data = $this->validate($data, null);

        if ($id !== null && User::whereKey($id)->exists()) {
            throw new ApiException('user_id_taken', 'El identificador del usuario ya existe.', 409);
        }

        return DB::transaction(function () use ($actor, $data, $id) {
            $user = new User([
                'given_names' => $data['given_names'],
                'paternal_surname' => $data['paternal_surname'],
                'maternal_surname' => $data['maternal_surname'] ?? null,
                'phone' => $data['phone'],
                'address' => $data['address'],
                'email' => $data['email'],
            ]);
            $user->name = PersonData::fullName($user->given_names, $user->paternal_surname, $user->maternal_surname);
            if ($id !== null) {
                $user->id = $id;
            }
            $user->community_id = $this->communityId();
            $this->applyPassword($user, $data);
            $user->save();
            $user->assignRole(Role::findByName($data['role'], 'web'));

            $this->audit($actor, $user, 'user.created', ['name' => $user->name, 'role' => $data['role'], 'email' => $user->email]);

            return $user;
        });
    }

    /**
     * CDU26: actualiza datos, rol o contraseña.
     */
    public function update(User $actor, User $user, array $data): User
    {
        $data = $this->validate($data, $user);

        return DB::transaction(function () use ($actor, $user, $data) {
            $changes = [];

            foreach (['given_names', 'paternal_surname', 'maternal_surname', 'phone', 'address', 'email'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $user->{$field}) {
                    $changes[$field] = ['from' => $user->{$field}, 'to' => $data[$field]];
                    $user->{$field} = $data[$field];
                }
            }
            if ($user->given_names !== null && $user->paternal_surname !== null) {
                $user->name = PersonData::fullName($user->given_names, $user->paternal_surname, $user->maternal_surname);
            }

            $passwordChanged = $this->applyPassword($user, $data);
            if ($passwordChanged) {
                // Solo se anota que cambió; la bitácora nunca guarda contraseñas.
                $changes['password_changed'] = true;
            }
            $user->save();

            if (isset($data['role']) && $data['role'] !== $user->panel()) {
                if ($actor->is($user)) {
                    throw new ApiException('cannot_change_own_role', 'No puede cambiar su propio rol.', 422);
                }
                $this->ensureNotLastAdmin($user);
                $changes['role'] = ['from' => $user->panel(), 'to' => $data['role']];
                $user->syncRoles([Role::findByName($data['role'], 'web')]);
            }

            // Con contraseña nueva, las sesiones abiertas se cierran.
            if ($passwordChanged) {
                $user->tokens()->delete();
            }

            if ($changes !== []) {
                $this->audit($actor, $user, 'user.updated', ['name' => $user->name, 'changes' => $changes]);
            }

            return $user;
        });
    }

    /**
     * CDU27: reactiva una cuenta desactivada.
     */
    public function activate(User $actor, User $user): User
    {
        if ($user->is_active) {
            throw new ApiException('already_active', 'El usuario ya está activo.', 409);
        }

        $user->forceFill(['is_active' => true, 'failed_login_attempts' => 0, 'locked_until' => null])->save();
        $this->audit($actor, $user, 'user.activated', ['name' => $user->name]);

        return $user;
    }

    /**
     * CDU28: desactiva la cuenta y revoca sus tokens; el historial se conserva.
     *
     * @return list<array{code: string, message: string}> avisos para el administrador
     */
    public function deactivate(User $actor, User $user): array
    {
        if (! $user->is_active) {
            throw new ApiException('already_inactive', 'El usuario ya está desactivado.', 409);
        }
        if ($actor->is($user)) {
            throw new ApiException('cannot_deactivate_self', 'No puede desactivar su propia cuenta.', 422);
        }
        $this->ensureNotLastAdmin($user);

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();
            $this->audit($actor, $user, 'user.deactivated', ['name' => $user->name]);
        });

        $notices = [];
        if ($user->hasRole(Roles::COBRADOR) && ($count = $this->assignments->countAssigned($user)) > 0) {
            $notices[] = [
                'code' => 'reassign_viviendas',
                'message' => "El cobrador tiene {$count} vivienda(s) asignada(s); deben reasignarse a otro cobrador.",
            ];
        }

        return $notices;
    }

    /**
     * Forma pública de un usuario (API y feed de sincronización). Nunca incluye el hash.
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'given_names' => $user->given_names,
            'paternal_surname' => $user->paternal_surname,
            'maternal_surname' => $user->maternal_surname,
            'phone' => $user->phone,
            'address' => $user->address,
            'email' => $user->email,
            'role' => $user->panel(),
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    private function validate(array $data, ?User $existing): array
    {
        $creating = $existing === null;
        $data = PersonData::prepare($data);

        $validator = Validator::make($data, [
            ...PersonData::rules($creating),
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($existing?->id)],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(Permissions::ASSIGNABLE_ROLES)],
            'password' => ['sometimes', 'nullable', 'string', 'min:10', 'max:255'],
            'password_hash' => ['sometimes', 'nullable', 'string', 'regex:'.self::BCRYPT_PATTERN],
        ], [
            ...PersonData::messages(),
            'email.unique' => 'Ese correo ya está registrado.',
            'role.in' => 'Rol no válido. Use administrador, cobrador o habitante.',
            'password_hash.regex' => 'La contraseña no se pudo guardar; escríbela de nuevo.',
        ]);

        $validator->after(function ($v) use ($data, $creating) {
            $hasPassword = ! empty($data['password']) || ! empty($data['password_hash']);
            if ($creating && ! $hasPassword) {
                $v->errors()->add('password', 'La contraseña es obligatoria (mínimo 10 caracteres).');
            }
        });

        $valid = $validator->validate();
        if (isset($valid['email'])) {
            $valid['email'] = mb_strtolower(trim($valid['email']));
        }

        return $valid;
    }

    private function applyPassword(User $user, array $data): bool
    {
        if (! empty($data['password'])) {
            $user->password = $data['password'];

            return true;
        }

        if (! empty($data['password_hash'])) {
            // Ya viene como bcrypt; se guarda sin volver a pasar por el cast "hashed".
            $user->setRawAttributes(['password' => $data['password_hash']] + $user->getAttributes());

            return true;
        }

        return false;
    }

    private function ensureNotLastAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            return;
        }

        $otherActiveAdmins = User::role(Roles::ADMIN)
            ->where('community_id', $user->community_id)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw new ApiException('last_admin', 'La comunidad debe conservar al menos un administrador activo.', 422);
        }
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }

    private function audit(User $actor, User $user, string $action, array $data = []): void
    {
        AuditLog::record($action, [
            'user_id' => $actor->id,
            'entity' => 'users',
            'entity_id' => $user->id,
            'data' => $data,
        ]);
    }
}
