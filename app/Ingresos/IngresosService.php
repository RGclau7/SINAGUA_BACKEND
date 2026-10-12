<?php

namespace App\Ingresos;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\CategoriaIngreso;
use App\Models\Ingreso;
use App\Models\SyncChange;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Ingresos extras (M8). El administrador crea las categorías que quiera (cada una es un fondo
 * con su saldo) y registra lo que entra a cada una. La usan la API REST y la sincronización;
 * la sincronización publica sus propios cambios y la API llama a publish().
 */
class IngresosService
{
    public const MAX_AMOUNT_CENTS = 100_000_000; // $1,000,000.00

    public function __construct(private readonly CurrentCommunity $context) {}

    public function findCategoria(string $id): CategoriaIngreso
    {
        $this->communityId();

        return CategoriaIngreso::find($id) ?? throw new ApiException('not_found', 'Categoría no encontrada.', 404);
    }

    /** @param array{name?: mixed, description?: mixed} $data */
    public function createCategoria(User $actor, array $data, ?string $id = null): CategoriaIngreso
    {
        $this->communityId();
        $fields = $this->categoriaFields($data, null);
        $this->ensureNewId(CategoriaIngreso::class, $id);

        $categoria = new CategoriaIngreso;
        if ($id !== null) {
            $categoria->id = $id;
        }
        $categoria->forceFill([...$fields, 'is_active' => true])->save();

        AuditLog::record('categoria_ingreso.created', [
            'user_id' => $actor->id,
            'entity' => CategoriaIngreso::ENTITY,
            'entity_id' => $categoria->id,
            'data' => ['name' => $categoria->name],
        ]);

        return $categoria;
    }

    /** Cambia nombre, descripción o si se sigue usando (is_active). */
    public function updateCategoria(User $actor, CategoriaIngreso $categoria, array $data): CategoriaIngreso
    {
        $fields = $this->categoriaFields([...$categoria->only(['name', 'description']), ...$data], $categoria->id);
        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = (bool) $data['is_active'];
        }

        $categoria->forceFill($fields);
        $changes = $categoria->getDirty();
        if ($changes === []) {
            return $categoria;
        }
        $before = array_intersect_key($categoria->getOriginal(), $changes);
        $categoria->save();

        AuditLog::record('categoria_ingreso.updated', [
            'user_id' => $actor->id,
            'entity' => CategoriaIngreso::ENTITY,
            'entity_id' => $categoria->id,
            'data' => [
                'name' => $categoria->name,
                'changes' => collect(array_diff_key($changes, ['updated_at' => 1]))
                    ->map(fn ($to, $field) => ['from' => $before[$field] ?? null, 'to' => $to])->all(),
            ],
        ]);

        return $categoria;
    }

    /**
     * Registra dinero que entra a una categoría.
     *
     * @param  array{categoria_id?: mixed, amount_cents?: mixed, received_on?: mixed, note?: mixed}  $data
     */
    public function createIngreso(User $actor, array $data, ?string $id = null, ?string $deviceId = null): Ingreso
    {
        $this->communityId();
        $v = Validator::make($data, [
            'categoria_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT_CENTS],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'categoria_id.required' => 'Elija la categoría del ingreso.',
            'amount_cents.required' => 'Escriba el monto.',
            'amount_cents.min' => 'El monto debe ser mayor a cero.',
            'amount_cents.max' => 'El monto es demasiado grande.',
            'received_on.required' => 'Escriba la fecha.',
            'received_on.date_format' => 'La fecha no es válida.',
        ])->validate();

        $categoria = CategoriaIngreso::find($v['categoria_id']) ?? throw new ApiException('not_found', 'Categoría no encontrada.', 404);
        if (! $categoria->is_active) {
            throw new ApiException('categoria_inactive', "La categoría {$categoria->name} ya no se usa; actívela para registrar ingresos.", 422);
        }
        if (CarbonImmutable::parse($v['received_on'])->gt(CarbonImmutable::today()->addDay())) {
            throw new ApiException('validation_error', 'La fecha del ingreso no puede ser futura.', 422);
        }
        $this->ensureNewId(Ingreso::class, $id);

        $ingreso = new Ingreso;
        if ($id !== null) {
            $ingreso->id = $id;
        }
        $note = trim(preg_replace('/\s+/', ' ', (string) ($v['note'] ?? '')));
        $ingreso->forceFill([
            'categoria_id' => $categoria->id,
            'amount_cents' => (int) $v['amount_cents'],
            'received_on' => $v['received_on'],
            'note' => $note === '' ? null : $note,
            'recorded_by' => $actor->id,
            'device_id' => $deviceId,
        ])->save();

        AuditLog::record('ingreso.created', [
            'user_id' => $actor->id,
            'device_id' => $deviceId,
            'entity' => Ingreso::ENTITY,
            'entity_id' => $ingreso->id,
            'data' => ['categoria' => $categoria->name, 'amount_cents' => $ingreso->amount_cents, 'received_on' => $v['received_on']],
        ]);

        return $ingreso;
    }

    /** Publica en el feed un cambio hecho fuera de /sync/push (REST o anulación). */
    public function publish(Model $record, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $record->community_id,
            'entity' => $record instanceof Ingreso ? Ingreso::ENTITY : CategoriaIngreso::ENTITY,
            'entity_id' => $record->id,
            'op' => $op,
            'payload' => self::present($record->fresh()),
            'created_at' => now(),
        ])->save();
    }

    public static function present(Model $record): array
    {
        if ($record instanceof CategoriaIngreso) {
            return [
                'id' => $record->id,
                'name' => $record->name,
                'description' => $record->description,
                'is_active' => (bool) $record->is_active,
                'created_at' => $record->created_at?->toIso8601String(),
                'updated_at' => $record->updated_at?->toIso8601String(),
            ];
        }

        /** @var Ingreso $record */
        return [
            'id' => $record->id,
            'categoria_id' => $record->categoria_id,
            'amount_cents' => $record->amount_cents,
            'received_on' => $record->received_on?->format('Y-m-d'),
            'note' => $record->note,
            'recorded_by' => $record->recorded_by,
            'voided_at' => $record->voided_at?->toIso8601String(),
            'voided_by' => $record->voided_by,
            'void_reason' => $record->void_reason,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{name: string, description: ?string} */
    private function categoriaFields(array $data, ?string $exceptId): array
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($data['name'] ?? '')));
        $description = trim(preg_replace('/\s+/', ' ', (string) ($data['description'] ?? '')));
        if (mb_strlen($name) < 2) {
            throw new ApiException('validation_error', 'Escriba el nombre de la categoría.', 422);
        }
        if (mb_strlen($name) > 80 || mb_strlen($description) > 255) {
            throw new ApiException('validation_error', 'El nombre o la descripción son demasiado largos.', 422);
        }
        $taken = CategoriaIngreso::query()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['id', 'name'])
            ->contains(fn (CategoriaIngreso $c) => self::key($c->name) === self::key($name));
        if ($taken) {
            throw new ApiException('duplicate_name', "Ya existe la categoría {$name}.", 409);
        }

        return ['name' => $name, 'description' => $description === '' ? null : $description];
    }

    /** "Baños" y "banos" son la misma categoría. */
    private static function key(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii($name))));
    }

    /** @param class-string<Model> $model */
    private function ensureNewId(string $model, ?string $id): void
    {
        if ($id !== null && $this->context->runAsSystem(fn () => $model::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }
}
