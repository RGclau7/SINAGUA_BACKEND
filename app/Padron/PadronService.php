<?php

namespace App\Padron;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Familia;
use App\Models\Habitante;
use App\Models\User;
use App\Models\Vivienda;
use App\Support\PersonData;
use App\Tenancy\CurrentCommunity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Padrón de la comunidad actual (CDU3 a CDU5): viviendas, familias y habitantes.
 * La usan la API REST y la sincronización del escritorio, así ambas aplican las mismas reglas.
 *
 * Los cambios que una operación hace en otros registros (por ejemplo, dar de baja las familias
 * de una vivienda) se publican aquí; el registro principal lo publica quien llama.
 */
class PadronService
{
    public const VIVIENDAS = 'viviendas';

    public const FAMILIAS = 'familias';

    public const HABITANTES = 'habitantes';

    public const ENTITIES = [self::VIVIENDAS => Vivienda::class, self::FAMILIAS => Familia::class, self::HABITANTES => Habitante::class];

    /** Motivo de las bajas en cascada; al reactivar al padre, se reactivan con él. */
    public const CASCADE_VIVIENDA = 'Baja de la vivienda';

    public const CASCADE_FAMILIA = 'Baja de la familia';

    private const NOT_FOUND = [
        self::VIVIENDAS => 'Vivienda no encontrada.',
        self::FAMILIAS => 'Familia no encontrada.',
        self::HABITANTES => 'Habitante no encontrado.',
    ];

    public function __construct(
        private readonly CurrentCommunity $context,
        private readonly Debts $debts,
        private readonly PadronPublisher $publisher,
    ) {}

    public static function entityOf(Model $record): string
    {
        return array_search($record::class, self::ENTITIES, true);
    }

    public function find(string $entity, string $id): Model
    {
        $this->communityId();
        $model = self::ENTITIES[$entity] ?? throw new ApiException('not_found', 'Tipo de registro no reconocido.', 404);

        return $model::find($id) ?? throw new ApiException('not_found', self::NOT_FOUND[$entity], 404);
    }

    // ---- Alta completa (CDU3) ---------------------------------------------------------------

    /**
     * Registra en un paso la familia, su titular y, si hace falta, la vivienda.
     * Si el domicilio ya tiene familias activas pide confirmación (CDU3, 4a) y, confirmada,
     * registra la familia en esa misma vivienda.
     *
     * @param  array{vivienda_id?: string, vivienda?: array, familia?: array, titular?: array, confirm_shared_address?: bool}  $data
     * @return array{vivienda: Vivienda, familia: Familia, titular: Habitante, vivienda_created: bool}
     */
    public function register(User $actor, array $data): array
    {
        $confirm = (bool) ($data['confirm_shared_address'] ?? false);

        return DB::transaction(function () use ($actor, $data, $confirm) {
            $viviendaCreated = false;
            if (! empty($data['vivienda_id'])) {
                $vivienda = $this->find(self::VIVIENDAS, $data['vivienda_id']);
            } else {
                $fields = $this->validateVivienda($data['vivienda'] ?? [], true);
                $vivienda = Vivienda::where('address_key', Vivienda::addressKey($fields['address'], $fields['neighborhood'] ?? null))
                    ->where('is_active', true)
                    ->first();
                if ($vivienda === null) {
                    $vivienda = $this->createVivienda($actor, $fields);
                    $viviendaCreated = true;
                }
            }

            if (! $viviendaCreated) {
                $this->ensureSharedAddressConfirmed($vivienda, $confirm);
            }

            $familiaData = $data['familia'] ?? [];
            if (blank($familiaData['name'] ?? null)) {
                // Sin nombre, la familia se llama como los apellidos del titular.
                $familiaData['name'] = self::familyNameFor($data['titular'] ?? []);
            }
            $familia = $this->createFamilia($actor, [...$familiaData, 'vivienda_id' => $vivienda->id]);
            $titular = $this->createHabitante($actor, [...($data['titular'] ?? []), 'familia_id' => $familia->id]);
            $familia = $this->updateFamilia($actor, $familia, ['head_id' => $titular->id]);

            return ['vivienda' => $vivienda, 'familia' => $familia, 'titular' => $titular, 'vivienda_created' => $viviendaCreated];
        });
    }

    // ---- Viviendas ------------------------------------------------------------------------

    public function createVivienda(User $actor, array $data, ?string $id = null): Vivienda
    {
        $data = $this->validateVivienda($data, true);
        $this->ensureNewId(Vivienda::class, $id);

        $vivienda = new Vivienda($data);
        if ($id !== null) {
            $vivienda->id = $id;
        }
        $vivienda->address_key = Vivienda::addressKey($vivienda->address, $vivienda->neighborhood);
        $vivienda->save();

        $this->audit($actor, $vivienda, 'vivienda.created', ['address' => $vivienda->label()]);

        return $vivienda;
    }

    public function updateVivienda(User $actor, Vivienda $vivienda, array $data): Vivienda
    {
        $data = $this->validateVivienda($data, false);
        $changes = $this->fill($vivienda, $data, ['address', 'neighborhood', 'reference']);

        if ($changes !== []) {
            $vivienda->address_key = Vivienda::addressKey($vivienda->address, $vivienda->neighborhood);
            $vivienda->save();
            $this->audit($actor, $vivienda, 'vivienda.updated', ['address' => $vivienda->label(), 'changes' => $changes]);
        }

        return $vivienda;
    }

    // ---- Familias -------------------------------------------------------------------------

    public function createFamilia(User $actor, array $data, ?string $id = null): Familia
    {
        $data = $this->validateFamilia($data, true);
        $this->ensureNewId(Familia::class, $id);
        $vivienda = $this->activeParent(self::VIVIENDAS, $data['vivienda_id'], 'La vivienda está dada de baja; reactívela antes de registrar familias.');

        $familia = new Familia(['name' => $data['name']]);
        $familia->cuota_especial_cents = $data['cuota_especial_cents'] ?? null;
        if ($id !== null) {
            $familia->id = $id;
        }
        $familia->vivienda_id = $vivienda->id;
        $familia->save();

        $this->audit($actor, $familia, 'familia.created', ['name' => $familia->name, 'address' => $vivienda->label()]);

        // El titular se fija después, cuando ya existe como habitante de la familia.
        if (! empty($data['head_id'])) {
            $this->updateFamilia($actor, $familia, ['head_id' => $data['head_id']]);
        }

        return $familia;
    }

    public function updateFamilia(User $actor, Familia $familia, array $data): Familia
    {
        $data = $this->validateFamilia($data, false);
        $changes = $this->fill($familia, $data, ['name', 'cuota_especial_cents']);

        if (array_key_exists('vivienda_id', $data) && $data['vivienda_id'] !== $familia->vivienda_id) {
            $to = $this->activeParent(self::VIVIENDAS, $data['vivienda_id'], 'No se puede mover la familia a una vivienda dada de baja.');
            $changes['vivienda'] = ['from' => $familia->vivienda?->label(), 'to' => $to->label()];
            $familia->vivienda_id = $to->id;
            $familia->unsetRelation('vivienda');
        }

        if (array_key_exists('head_id', $data) && $data['head_id'] !== $familia->head_id) {
            $head = null;
            if ($data['head_id'] !== null) {
                $head = Habitante::find($data['head_id']);
                if ($head === null || $head->familia_id !== $familia->id || ! $head->is_active) {
                    throw new ApiException('invalid_head', 'El titular debe ser un integrante activo de la familia.', 422);
                }
            }
            $changes['head'] = ['from' => $familia->head?->name, 'to' => $head?->name];
            $familia->head_id = $head?->id;
            $familia->unsetRelation('head');
        }

        if ($changes !== []) {
            $familia->save();
            $this->audit($actor, $familia, 'familia.updated', ['name' => $familia->name, 'changes' => $changes]);
        }

        return $familia;
    }

    // ---- Habitantes -----------------------------------------------------------------------

    public function createHabitante(User $actor, array $data, ?string $id = null): Habitante
    {
        $data = $this->validateHabitante($data, true);
        $this->ensureNewId(Habitante::class, $id);
        $familia = $this->activeParent(self::FAMILIAS, $data['familia_id'], 'La familia está dada de baja; reactívela antes de agregar integrantes.');

        $habitante = new Habitante($data);
        if ($id !== null) {
            $habitante->id = $id;
        }
        $habitante->familia_id = $familia->id;
        $habitante->name = PersonData::fullName($habitante->given_names, $habitante->paternal_surname, $habitante->maternal_surname);
        $habitante->save();

        $this->audit($actor, $habitante, 'habitante.created', ['name' => $habitante->name, 'familia' => $familia->name]);

        return $habitante;
    }

    public function updateHabitante(User $actor, Habitante $habitante, array $data): Habitante
    {
        $data = $this->validateHabitante($data, false);
        $changes = $this->fill($habitante, $data, ['given_names', 'paternal_surname', 'maternal_surname', 'phone']);
        $habitante->name = PersonData::fullName($habitante->given_names, $habitante->paternal_surname, $habitante->maternal_surname);

        if (array_key_exists('familia_id', $data) && $data['familia_id'] !== $habitante->familia_id) {
            $from = $habitante->familia;
            $to = $this->activeParent(self::FAMILIAS, $data['familia_id'], 'No se puede pasar a una familia dada de baja.');
            $changes['familia'] = ['from' => $from?->name, 'to' => $to->name];
            $habitante->familia_id = $to->id;
            $habitante->unsetRelation('familia');
            $this->releaseHead($actor, $from, $habitante);
        }

        if ($changes !== []) {
            $habitante->save();
            $this->audit($actor, $habitante, 'habitante.updated', ['name' => $habitante->name, 'changes' => $changes]);
        }

        return $habitante;
    }

    // ---- Baja lógica y reactivación (CDU5) ------------------------------------------------

    /**
     * Marca el registro como inactivo; nunca lo borra. Si la vivienda o familia tiene adeudo,
     * hay que confirmar (CDU5, 3b): sin confirmación responde has_debt con el monto.
     * La baja de una vivienda da de baja también a sus familias, y la de una familia a sus integrantes.
     *
     * @return list<array{code: string, message: string}> avisos para el administrador
     */
    public function deactivate(User $actor, Model $record, ?string $reason = null, bool $confirmDebt = false): array
    {
        if (! $record->is_active) {
            throw new ApiException('already_inactive', $this->label($record, 'ya está dada de baja.', 'ya está dado de baja.'), 409);
        }
        $reason = $this->validateReason($reason);
        $notices = [];

        $owed = match (true) {
            $record instanceof Vivienda => $this->debts->owedByVivienda($record),
            $record instanceof Familia => $this->debts->owedByFamilia($record),
            default => 0,
        };
        if ($owed > 0) {
            if (! $confirmDebt) {
                throw new ApiException('has_debt', $this->label($record, 'tiene un adeudo de '.self::money($owed).'. ¿Darla de baja de todos modos?', 'tiene un adeudo de '.self::money($owed).'.'), 409, ['amount_cents' => $owed]);
            }
            $notices[] = ['code' => 'had_debt', 'message' => 'Se dio de baja con un adeudo de '.self::money($owed).'; el adeudo se conserva.'];
        }

        DB::transaction(function () use ($actor, $record, $reason, $owed, &$notices) {
            $this->markInactive($record, $reason);
            $this->audit($actor, $record, self::action($record, 'deactivated'), array_filter([
                ...$this->identity($record),
                'reason' => $reason,
                'debt_cents' => $owed ?: null,
            ]));

            $cascaded = $this->cascadeDeactivation($actor, $record);
            if ($cascaded['familias'] > 0) {
                $notices[] = ['code' => 'familias_deactivated', 'message' => "También se dieron de baja {$cascaded['familias']} familia(s) de esta vivienda."];
            }
            if ($cascaded['habitantes'] > 0) {
                $notices[] = ['code' => 'habitantes_deactivated', 'message' => "También se dieron de baja {$cascaded['habitantes']} integrante(s)."];
            }

            if ($record instanceof Habitante && $this->releaseHead($actor, $record->familia, $record)) {
                $notices[] = ['code' => 'family_without_head', 'message' => "La familia {$record->familia->name} se quedó sin titular; elija uno nuevo."];
            }
        });

        return $notices;
    }

    public function activate(User $actor, Model $record): Model
    {
        if ($record->is_active) {
            throw new ApiException('already_active', $this->label($record, 'ya está activa.', 'ya está activo.'), 409);
        }
        if ($record instanceof Familia && ! $record->vivienda->is_active) {
            throw new ApiException('parent_inactive', 'Primero reactive la vivienda de esta familia.', 422);
        }
        if ($record instanceof Habitante && ! $record->familia->is_active) {
            throw new ApiException('parent_inactive', 'Primero reactive la familia de este habitante.', 422);
        }

        DB::transaction(function () use ($actor, $record) {
            $record->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivation_reason' => null])->save();
            $this->audit($actor, $record, self::action($record, 'activated'), $this->identity($record));
            $this->cascadeActivation($actor, $record);
        });

        return $record;
    }

    // ---- Forma pública (API y feed de sincronización) -------------------------------------

    public static function present(Model $record): array
    {
        $common = [
            'id' => $record->id,
            'is_active' => (bool) $record->is_active,
            'deactivated_at' => $record->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $record->deactivation_reason,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];

        return match (true) {
            $record instanceof Vivienda => [
                ...$common,
                'address' => $record->address,
                'neighborhood' => $record->neighborhood,
                'reference' => $record->reference,
            ],
            $record instanceof Familia => [
                ...$common,
                'vivienda_id' => $record->vivienda_id,
                'name' => $record->name,
                'head_id' => $record->head_id,
                'cuota_especial_cents' => $record->cuota_especial_cents,
            ],
            $record instanceof Habitante => [
                ...$common,
                'familia_id' => $record->familia_id,
                'name' => $record->name,
                'given_names' => $record->given_names,
                'paternal_surname' => $record->paternal_surname,
                'maternal_surname' => $record->maternal_surname,
                'phone' => $record->phone,
            ],
        };
    }

    public static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    // ---- Apoyo ---------------------------------------------------------------------------

    private function validateVivienda(array $data, bool $creating): array
    {
        $data = $this->clean($data, ['address', 'neighborhood', 'reference']);
        $req = $creating ? 'required' : 'sometimes';

        return Validator::make($data, [
            'address' => [$req, 'string', 'min:3', 'max:190'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:190'],
        ], [
            'address.required' => 'El domicilio (calle y número) es obligatorio.',
            'address.min' => 'Escriba el domicilio completo.',
        ])->validate();
    }

    private function validateFamilia(array $data, bool $creating): array
    {
        $data = $this->clean($data, ['name']);
        $req = $creating ? 'required' : 'sometimes';

        return Validator::make($data, [
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'vivienda_id' => [$req, 'uuid'],
            'head_id' => ['sometimes', 'nullable', 'uuid'],
            'cuota_especial_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
        ], [
            'cuota_especial_cents.min' => 'La cuota especial debe ser mayor a cero (o déjela vacía).',
            'name.required' => 'El nombre de la familia es obligatorio.',
            'name.min' => 'Escriba el nombre de la familia.',
            'vivienda_id.required' => 'Elija la vivienda de la familia.',
            'vivienda_id.uuid' => 'Elija la vivienda de la familia.',
        ])->validate();
    }

    private function validateHabitante(array $data, bool $creating): array
    {
        $data = PersonData::prepare($data);
        if (array_key_exists('phone', $data) && $data['phone'] === '') {
            $data['phone'] = null;
        }
        $req = $creating ? 'required' : 'sometimes';
        $rules = PersonData::rules($creating);

        return Validator::make($data, [
            'given_names' => $rules['given_names'],
            'paternal_surname' => $rules['paternal_surname'],
            'maternal_surname' => $rules['maternal_surname'],
            // Muchas personas no tienen teléfono: es opcional, pero si se escribe, de 10 dígitos.
            'phone' => ['sometimes', 'nullable', 'string', 'regex:'.PersonData::PHONE_PATTERN],
            'familia_id' => [$req, 'uuid'],
        ], [
            ...PersonData::messages(),
            'familia_id.required' => 'Elija la familia del habitante.',
            'familia_id.uuid' => 'Elija la familia del habitante.',
        ])->validate();
    }

    private function validateReason(?string $reason): ?string
    {
        $reason = trim(preg_replace('/\s+/', ' ', (string) $reason));
        if (mb_strlen($reason) > 190) {
            throw new ApiException('validation_error', 'El motivo es demasiado largo (máximo 190 caracteres).', 422);
        }

        return $reason === '' ? null : $reason;
    }

    /** Quita espacios de más; los campos opcionales vacíos quedan en null. */
    private function clean(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim(preg_replace('/\s+/', ' ', $data[$field]));
                if ($data[$field] === '') {
                    $data[$field] = null;
                }
            }
        }

        return $data;
    }

    /** Aplica los campos que cambiaron y devuelve el detalle para la bitácora. */
    private function fill(Model $record, array $data, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $record->{$field}) {
                $changes[$field] = ['from' => $record->{$field}, 'to' => $data[$field]];
                $record->{$field} = $data[$field];
            }
        }

        return $changes;
    }

    private function activeParent(string $entity, string $id, string $inactiveMessage): Model
    {
        $parent = $this->find($entity, $id);
        if (! $parent->is_active) {
            throw new ApiException('parent_inactive', $inactiveMessage, 422);
        }

        return $parent;
    }

    private function ensureSharedAddressConfirmed(Vivienda $vivienda, bool $confirmed): void
    {
        $names = $vivienda->familias()->where('is_active', true)->orderBy('name')->pluck('name');
        if ($names->isNotEmpty() && ! $confirmed) {
            throw new ApiException('address_has_family',
                "En {$vivienda->label()} ya vive: {$names->implode(', ')}. ¿Registrar otra familia en el mismo domicilio?",
                409, ['vivienda_id' => $vivienda->id, 'familias' => $names->all()]);
        }
    }

    /**
     * La baja de una vivienda da de baja a sus familias, y la de una familia a sus integrantes.
     *
     * @return array{familias: int, habitantes: int}
     */
    private function cascadeDeactivation(User $actor, Model $record): array
    {
        $count = ['familias' => 0, 'habitantes' => 0];
        $familias = match (true) {
            $record instanceof Vivienda => $record->familias()->where('is_active', true)->get(),
            $record instanceof Familia => collect([$record]),
            default => collect(),
        };

        foreach ($familias as $familia) {
            if ($familia->isNot($record)) {
                $this->markInactive($familia, self::CASCADE_VIVIENDA);
                $this->audit($actor, $familia, 'familia.deactivated', [...$this->identity($familia), 'reason' => self::CASCADE_VIVIENDA]);
                $this->publisher->publish($familia, 'update');
                $count['familias']++;
            }
            foreach ($familia->habitantes()->where('is_active', true)->get() as $habitante) {
                $this->markInactive($habitante, self::CASCADE_FAMILIA);
                $this->audit($actor, $habitante, 'habitante.deactivated', [...$this->identity($habitante), 'reason' => self::CASCADE_FAMILIA]);
                $this->publisher->publish($habitante, 'update');
                $count['habitantes']++;
            }
        }

        return $count;
    }

    /** Al reactivar, vuelven también los que se dieron de baja junto con este registro. */
    private function cascadeActivation(User $actor, Model $record): void
    {
        $familias = match (true) {
            $record instanceof Vivienda => $record->familias()->where('is_active', false)->where('deactivation_reason', self::CASCADE_VIVIENDA)->get(),
            $record instanceof Familia => collect([$record]),
            default => collect(),
        };

        foreach ($familias as $familia) {
            if ($familia->isNot($record)) {
                $familia->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivation_reason' => null])->save();
                $this->audit($actor, $familia, 'familia.activated', $this->identity($familia));
                $this->publisher->publish($familia, 'update');
            }
            $habitantes = $familia->habitantes()->where('is_active', false)->where('deactivation_reason', self::CASCADE_FAMILIA)->get();
            foreach ($habitantes as $habitante) {
                $habitante->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivation_reason' => null])->save();
                $this->audit($actor, $habitante, 'habitante.activated', $this->identity($habitante));
                $this->publisher->publish($habitante, 'update');
            }
        }
    }

    /** "López Ruiz" a partir de los apellidos del titular. */
    public static function familyNameFor(array $titular): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', ($titular['paternal_surname'] ?? '').' '.($titular['maternal_surname'] ?? '')));

        return $name === '' ? null : $name;
    }

    /** Si el habitante era titular de la familia, la familia se queda sin titular. */
    private function releaseHead(User $actor, ?Familia $familia, Habitante $habitante): bool
    {
        if ($familia === null || $familia->head_id !== $habitante->id) {
            return false;
        }

        $familia->head_id = null;
        $familia->save();
        $this->audit($actor, $familia, 'familia.updated', ['name' => $familia->name, 'changes' => ['head' => ['from' => $habitante->name, 'to' => null]]]);
        $this->publisher->publish($familia, 'update');

        return true;
    }

    private function markInactive(Model $record, ?string $reason): void
    {
        $record->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivation_reason' => $reason])->save();
    }

    private function ensureNewId(string $model, ?string $id): void
    {
        if ($id !== null && app(CurrentCommunity::class)->runAsSystem(fn () => $model::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }
    }

    private function identity(Model $record): array
    {
        return match (true) {
            $record instanceof Vivienda => ['address' => $record->label()],
            $record instanceof Familia => ['name' => $record->name],
            $record instanceof Habitante => ['name' => $record->name],
        };
    }

    private function label(Model $record, string $feminine, string $masculine): string
    {
        return match (true) {
            $record instanceof Vivienda => "La vivienda {$record->label()} {$feminine}",
            $record instanceof Familia => "La familia {$record->name} {$feminine}",
            $record instanceof Habitante => "{$record->name} {$masculine}",
        };
    }

    private static function action(Model $record, string $verb): string
    {
        return match (true) {
            $record instanceof Vivienda => 'vivienda.',
            $record instanceof Familia => 'familia.',
            $record instanceof Habitante => 'habitante.',
        }.$verb;
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }

    private function audit(User $actor, Model $record, string $action, array $data): void
    {
        AuditLog::record($action, [
            'user_id' => $actor->id,
            'entity' => self::entityOf($record),
            'entity_id' => $record->id,
            'data' => $data,
        ]);
    }
}
