<?php

namespace App\Cuotas;

use App\Comunidad\Calendario;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Cargo;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\Familia;
use App\Models\Periodo;
use App\Models\User;
use App\Padron\PadronService;
use App\Tenancy\CurrentCommunity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Cuotas y cargos de la comunidad actual (M5). La usan la API REST y la sincronización.
 *
 * Los identificadores de periodos y cargos se derivan de la comunidad, el mes y la familia
 * (UUID v5), igual que en el escritorio: si dos equipos generan el mismo mes sin conexión,
 * los cargos coinciden y nunca se duplican.
 */
class CuotasService
{
    public const CUOTAS = 'cuotas';

    public const PERIODOS = 'periodos';

    /** Espacio de nombres de los UUID v5 de SINAGUA (no cambiar: el escritorio usa el mismo). */
    public const UUID_NAMESPACE = '0f3c7f0e-5b8a-4d3e-9a51-6e2b1c9d4a70';

    /** Monto máximo de una cuota: $100,000.00. */
    public const MAX_CENTS = 10_000_000;

    public function __construct(
        private readonly CurrentCommunity $context,
        private readonly CuotasPublisher $publisher,
    ) {}

    public static function periodoId(string $communityId, int $year, int $month): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, sprintf('periodo:%s:%04d-%02d', $communityId, $year, $month))->toString();
    }

    public static function cargoId(string $periodoId, string $familiaId): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, "cargo:{$periodoId}:{$familiaId}")->toString();
    }

    // ---- Cuotas ---------------------------------------------------------------------------

    public function findCuota(string $id): Cuota
    {
        $this->communityId();

        return Cuota::find($id) ?? throw new ApiException('not_found', 'Cuota no encontrada.', 404);
    }

    /**
     * Registra la cuota mensual que aplica desde un mes ("2026-11").
     */
    public function createCuota(User $actor, array $data, ?string $id = null): Cuota
    {
        $this->communityId();
        $data = $this->validateCuota($data, true);
        if ($id !== null && $this->context->runAsSystem(fn () => Cuota::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }
        $this->ensureFreeMonth($data['valid_from']);

        $cuota = new Cuota($data);
        if ($id !== null) {
            $cuota->id = $id;
        }
        $cuota->save();

        $this->audit($actor, $cuota, 'cuota.created', [
            'amount_cents' => $cuota->amount_cents,
            'valid_from' => $cuota->valid_from->format('Y-m'),
            'note' => $cuota->note,
        ]);

        return $cuota;
    }

    /**
     * Corrige una cuota mientras no se haya usado para generar cargos; después, el cambio de
     * monto se hace registrando otra cuota con otra fecha, para conservar el historial.
     */
    public function updateCuota(User $actor, Cuota $cuota, array $data): Cuota
    {
        $data = $this->validateCuota($data, false);
        $used = Periodo::where('cuota_id', $cuota->id)->exists();
        $changes = [];

        foreach (['amount_cents', 'valid_from', 'note'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $before = $field === 'valid_from' ? $cuota->valid_from->format('Y-m-d') : $cuota->{$field};
            if ($data[$field] === $before) {
                continue;
            }
            if ($used && $field !== 'note') {
                throw new ApiException('cuota_in_use',
                    'Ya se generaron cargos con esta cuota. Para cambiar el monto, registre una cuota nueva a partir de otro mes.', 422);
            }
            if ($field === 'valid_from') {
                $this->ensureFreeMonth($data['valid_from'], $cuota->id);
            }
            $changes[$field] = [
                'from' => $field === 'valid_from' ? substr($before, 0, 7) : $before,
                'to' => $field === 'valid_from' ? substr($data[$field], 0, 7) : $data[$field],
            ];
            $cuota->{$field} = $data[$field];
        }

        if ($changes !== []) {
            $cuota->save();
            $this->audit($actor, $cuota, 'cuota.updated', ['changes' => $changes, 'valid_from' => $cuota->valid_from->format('Y-m')]);
        }

        return $cuota;
    }

    // ---- Generación de cargos -------------------------------------------------------------

    /**
     * Genera el cargo del mes para cada familia activa que aún no lo tenga. Se puede repetir:
     * la segunda vez solo agrega a las familias nuevas.
     *
     * @return array{periodo: Periodo, created: int, total_cents: int}
     */
    public function generate(?User $actor, int $year, int $month): array
    {
        $communityId = $this->communityId();
        $this->validateMonth($year, $month);

        return DB::transaction(function () use ($actor, $communityId, $year, $month) {
            $periodo = Periodo::where('year', $year)->where('month', $month)->first();
            $meses = (int) ($this->context->runAsSystem(fn () => Community::whereKey($communityId)->value('periodicidad_meses')) ?? 1);
            if ($periodo === null && ! Calendario::esInicio($month, $meses)) {
                throw new ApiException('not_period_start', 'Se cobra cada '.$meses.' meses: elija el primer mes del periodo ('
                    .Periodo::monthLabel($year, Calendario::inicio($month, $meses)).').', 422);
            }
            if ($periodo === null) {
                $cuota = Cuota::forMonth($year, $month)
                    ?? throw new ApiException('no_cuota', 'No hay una cuota vigente para '.Periodo::monthLabel($year, $month).'. Registre primero la cuota.', 422);
                $periodo = new Periodo;
                $periodo->id = self::periodoId($communityId, $year, $month);
                $periodo->forceFill(['year' => $year, 'month' => $month, 'meses' => $meses, 'cuota_id' => $cuota->id, 'amount_cents' => $cuota->amount_cents]);
                $periodo->save();
            }

            $charged = Cargo::where('periodo_id', $periodo->id)->where('concept', Cargo::CUOTA)->pluck('familia_id')->all();
            $familias = Familia::where('is_active', true)->whereNotIn('id', $charged)->orderBy('name')->get();

            $total = 0;
            foreach ($familias as $familia) {
                // Cada titular paga su cuota: la especial si la tiene, si no la del mes.
                $amount = $familia->cuota_especial_cents ?? $periodo->amount_cents;
                $total += $amount;
                $cargo = new Cargo;
                $cargo->id = self::cargoId($periodo->id, $familia->id);
                $cargo->forceFill([
                    'periodo_id' => $periodo->id,
                    'familia_id' => $familia->id,
                    'year' => $year,
                    'month' => $month,
                    'concept' => Cargo::CUOTA,
                    'meses' => $periodo->meses ?? 1,
                    'amount_cents' => $amount,
                ]);
                $cargo->save();
                $this->publisher->publish($cargo, 'create');
            }

            if ($familias->isNotEmpty()) {
                AuditLog::record('periodo.generated', [
                    'user_id' => $actor?->id,
                    'entity' => self::PERIODOS,
                    'entity_id' => $periodo->id,
                    'data' => [
                        'month' => $periodo->label(),
                        'familias' => $familias->count(),
                        'amount_cents' => $periodo->amount_cents,
                        'total_cents' => $total,
                    ],
                ]);
            }

            return ['periodo' => $periodo, 'created' => $familias->count(), 'total_cents' => $total];
        });
    }

    /** Aviso para el administrador después de generar. */
    public static function generatedNotice(array $result): string
    {
        $label = $result['periodo']->label();

        return $result['created'] === 0
            ? "Todas las familias activas ya tenían su cargo de {$label}."
            : "Se generaron {$result['created']} cargo(s) de {$label} por ".PadronService::money($result['total_cents']).'.';
    }

    /**
     * "Asignar la cuota del mes": deja el monto indicado como cuota de ese mes (registrando una
     * cuota a partir de ese mes si cambió) y genera los cargos.
     *
     * @return array{periodo: Periodo, created: int, total_cents: int, cuota: ?Cuota}
     */
    public function assignMonth(User $actor, int $year, int $month, ?int $amountCents): array
    {
        $this->validateMonth($year, $month);
        $cuota = null;
        $exists = Periodo::where('year', $year)->where('month', $month)->first();

        if ($amountCents !== null && $exists === null && Cuota::forMonth($year, $month)?->amount_cents !== $amountCents) {
            $first = sprintf('%04d-%02d-01', $year, $month);
            $sameMonth = Cuota::whereDate('valid_from', $first)->first();
            $cuota = $sameMonth !== null
                ? $this->updateCuota($actor, $sameMonth, ['amount_cents' => $amountCents])
                : $this->createCuota($actor, ['amount_cents' => $amountCents, 'valid_from' => $first]);
        } elseif ($amountCents !== null && $exists !== null && $exists->amount_cents !== $amountCents) {
            throw new ApiException('month_has_cuota', Periodo::monthLabel($year, $month).' ya se asignó con '.PadronService::money($exists->amount_cents).' por familia; ese monto ya no cambia.', 422);
        }

        return [...$this->generate($actor, $year, $month), 'cuota' => $cuota];
    }

    // ---- Multas ---------------------------------------------------------------------------

    /**
     * Asigna una multa (motivo y monto) a una familia activa en un mes.
     */
    public function createMulta(User $actor, string $familiaId, array $data, ?string $id = null): Cargo
    {
        $this->communityId();
        $data = Validator::make($data, [
            'description' => ['required', 'string', 'min:3', 'max:190'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer'],
        ], [
            'description.required' => 'Escriba el motivo de la multa.',
            'description.min' => 'Escriba el motivo de la multa.',
            'amount_cents.required' => 'El monto de la multa es obligatorio.',
            'amount_cents.min' => 'El monto debe ser mayor a cero.',
        ])->validate();
        $this->validateMonth((int) $data['year'], (int) $data['month']);

        $familia = Familia::find($familiaId) ?? throw new ApiException('not_found', 'Familia no encontrada.', 404);
        if (! $familia->is_active) {
            throw new ApiException('parent_inactive', "La familia {$familia->name} está dada de baja.", 422);
        }
        if ($id !== null && $this->context->runAsSystem(fn () => Cargo::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }

        $cargo = new Cargo;
        if ($id !== null) {
            $cargo->id = $id;
        }
        $cargo->forceFill([
            'familia_id' => $familia->id,
            'concept' => Cargo::MULTA,
            'description' => trim(preg_replace('/\s+/', ' ', $data['description'])),
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'amount_cents' => (int) $data['amount_cents'],
        ])->save();

        AuditLog::record('multa.created', [
            'user_id' => $actor->id,
            'entity' => Cargo::ENTITY,
            'entity_id' => $cargo->id,
            'data' => [
                'familia' => $familia->name,
                'description' => $cargo->description,
                'amount_cents' => $cargo->amount_cents,
                'month' => Periodo::monthLabel($cargo->year, $cargo->month),
            ],
        ]);

        return $cargo;
    }

    // ---- Forma pública ---------------------------------------------------------------------

    public static function present(Model $record): array
    {
        return match (true) {
            $record instanceof Cuota => [
                'id' => $record->id,
                'amount_cents' => $record->amount_cents,
                'valid_from' => $record->valid_from->format('Y-m-d'),
                'note' => $record->note,
                'updated_at' => $record->updated_at?->toIso8601String(),
            ],
            $record instanceof Periodo => [
                'id' => $record->id,
                'year' => $record->year,
                'month' => $record->month,
                'meses' => (int) ($record->meses ?? 1),
                'cuota_id' => $record->cuota_id,
                'amount_cents' => $record->amount_cents,
                // Para que el equipo quite los cargos que calculó sin conexión y el servidor no hizo.
                'cargo_ids' => $record->cargos()->pluck('id')->all(),
                'created_at' => $record->created_at?->toIso8601String(),
                'updated_at' => $record->updated_at?->toIso8601String(),
            ],
            $record instanceof Cargo => [
                'id' => $record->id,
                'periodo_id' => $record->periodo_id,
                'familia_id' => $record->familia_id,
                'concept' => $record->concept ?? Cargo::CUOTA,
                'description' => $record->description,
                'year' => $record->year,
                'month' => $record->month,
                'meses' => (int) ($record->meses ?? 1),
                'amount_cents' => $record->amount_cents,
                'voided_at' => $record->voided_at?->toIso8601String(),
                'voided_by' => $record->voided_by,
                'void_reason' => $record->void_reason,
                'created_at' => $record->created_at?->toIso8601String(),
            ],
        };
    }

    // ---- Apoyo ---------------------------------------------------------------------------

    private function validateCuota(array $data, bool $creating): array
    {
        if (isset($data['valid_from']) && is_string($data['valid_from']) && preg_match('/^\d{4}-\d{2}$/', $data['valid_from'])) {
            $data['valid_from'] .= '-01';
        }
        if (isset($data['note']) && is_string($data['note'])) {
            $data['note'] = trim($data['note']) === '' ? null : trim($data['note']);
        }
        $req = $creating ? 'required' : 'sometimes';

        $valid = Validator::make($data, [
            'amount_cents' => [$req, 'integer', 'min:1', 'max:'.self::MAX_CENTS],
            'valid_from' => [$req, 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:190'],
        ], [
            'amount_cents.required' => 'El monto de la cuota es obligatorio.',
            'amount_cents.min' => 'El monto debe ser mayor a cero.',
            'amount_cents.max' => 'El monto es demasiado alto.',
            'valid_from.required' => 'Indique desde qué mes aplica la cuota.',
            'valid_from.date_format' => 'Indique desde qué mes aplica la cuota.',
        ])->validate();

        if (isset($valid['valid_from']) && ! str_ends_with($valid['valid_from'], '-01')) {
            $valid['valid_from'] = substr($valid['valid_from'], 0, 8).'01';
        }

        return $valid;
    }

    private function ensureFreeMonth(string $validFrom, ?string $exceptId = null): void
    {
        $taken = Cuota::whereDate('valid_from', $validFrom)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw new ApiException('cuota_exists', 'Ya hay una cuota que empieza en '.Periodo::monthLabel((int) substr($validFrom, 0, 4), (int) substr($validFrom, 5, 2)).'.', 422);
        }
    }

    private function validateMonth(int $year, int $month): void
    {
        $max = now()->addMonths(12);
        if ($month < 1 || $month > 12 || $year < 2000 || sprintf('%04d-%02d', $year, $month) > $max->format('Y-m')) {
            throw new ApiException('validation_error', 'Elija un mes válido (hasta 12 meses adelante).', 422);
        }
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }

    private function audit(User $actor, Cuota $cuota, string $action, array $data): void
    {
        AuditLog::record($action, [
            'user_id' => $actor->id,
            'entity' => self::CUOTAS,
            'entity_id' => $cuota->id,
            'data' => $data,
        ]);
    }
}
