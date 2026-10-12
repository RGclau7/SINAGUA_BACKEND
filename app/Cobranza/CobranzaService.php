<?php

namespace App\Cobranza;

use App\Auth\AccessRules;
use App\Comunidad\AjustesService;
use App\Comunidad\Calendario;
use App\Cuotas\CuotasPublisher;
use App\Cuotas\CuotasService;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Cargo;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\Device;
use App\Models\Familia;
use App\Models\Pago;
use App\Models\PagoDetalle;
use App\Models\Periodo;
use App\Models\SyncChange;
use App\Models\User;
use App\Padron\PadronService;
use App\Tenancy\CurrentCommunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cobranza (M7): una familia paga completos los cargos que elige (cuotas de meses o multas).
 * No hay pagos parciales: cada cargo se paga por su monto. La usan la API REST y la sincronización.
 */
class CobranzaService
{
    /** Prefijo de los folios emitidos desde la web (los equipos usan el suyo: E01, E02…). */
    public const WEB_PREFIX = 'W';

    /** Equipo cuya mutación provocó el cambio (lo fija la sincronización). */
    public ?string $originDeviceId = null;

    public function __construct(private readonly CurrentCommunity $context) {}

    /** Cargos que aún no tienen un pago vigente. */
    public static function unpaid(): Builder
    {
        return Cargo::query()->whereNull('voided_at')->whereNotIn('id', self::paidIds());
    }

    /** Subconsulta: ids de cargos con un pago no anulado. */
    public static function paidIds(): \Illuminate\Database\Query\Builder
    {
        return PagoDetalle::query()->getQuery()
            ->select('pago_detalles.cargo_id')
            ->join('pagos', 'pagos.id', '=', 'pago_detalles.pago_id')
            ->whereNull('pagos.voided_at');
    }

    /**
     * Registra el pago de los cargos elegidos de una familia.
     *
     * @param  list<string>  $cargoIds
     * @param  array<string, int>|null  $amounts  monto que el equipo cobró por cargo (debe ser el completo)
     */
    public function pay(User $actor, string $familiaId, array $cargoIds, ?string $folio = null, ?string $deviceId = null,
        ?string $id = null, ?array $amounts = null, ?string $paidAt = null, array $advance = [], ?string $cobradores = null): Pago
    {
        $this->communityId();
        $familia = Familia::find($familiaId) ?? throw new ApiException('not_found', 'Familia no encontrada.', 404);
        if (! Gate::forUser($actor)->allows(AccessRules::COBRAR_VIVIENDA, $familia->vivienda_id)) {
            throw new ApiException('forbidden', 'Esta vivienda no está asignada a usted.', 403);
        }
        $cargoIds = array_values(array_unique($cargoIds));
        if ($cargoIds === []) {
            throw new ApiException('validation_error', 'Elija al menos un mes o multa para cobrar.', 422);
        }
        if ($id !== null && $this->context->runAsSystem(fn () => Pago::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del pago ya existe.', 409);
        }

        return DB::transaction(function () use ($actor, $familia, $cargoIds, $folio, $deviceId, $id, $amounts, $paidAt, $advance, $cobradores) {
            $folio = $folio === null ? $this->nextWebFolio() : $this->validateFolio($folio, $deviceId);
            // Si la comunidad solo cobra en sus días de pago, fuera de ellos no se recibe.
            $community = $this->context->runAsSystem(fn () => Community::find($familia->community_id));
            $when = $paidAt !== null ? now()->parse($paidAt) : now();
            if (! $community->cobro_fuera_de_dias && ! Calendario::esDiaDePago($community, $when)) {
                throw new ApiException('not_pay_day', 'Hoy no es día de cobro. Se cobra '.Calendario::describeDias(Calendario::dias($community)).' de cada mes.', 422);
            }
            // Meses por adelantado: su cargo se crea al pagarlos (con el mismo id que en el equipo).
            foreach ($advance as $month) {
                $this->ensureAdvanceCargo($familia, (int) ($month['year'] ?? 0), (int) ($month['month'] ?? 0));
            }
            $cargos = Cargo::whereIn('id', $cargoIds)->lockForUpdate()->get();
            if ($cargos->count() !== count($cargoIds) || $cargos->contains(fn (Cargo $c) => $c->familia_id !== $familia->id)) {
                throw new ApiException('invalid_cargos', 'Los cargos elegidos no son de esta familia.', 422);
            }
            if ($voided = $cargos->first(fn (Cargo $c) => $c->voided_at !== null)) {
                throw new ApiException('cargo_voided', self::concept($voided).' está anulado; ya no se cobra.', 409);
            }
            $alreadyPaid = PagoDetalle::whereIn('cargo_id', $cargoIds)
                ->whereHas('pago', fn ($q) => $q->whereNull('voided_at'))->with(['pago', 'cargo'])->first();
            if ($alreadyPaid !== null) {
                throw new ApiException('already_paid', self::concept($alreadyPaid->cargo).' ya se pagó con el folio '.$alreadyPaid->pago->folio.'.', 409);
            }
            // Sin pagos parciales: cada cargo se paga por su monto completo.
            foreach ($amounts ?? [] as $cargoId => $cents) {
                $cargo = $cargos->firstWhere('id', $cargoId);
                if ($cargo !== null && (int) $cents !== $cargo->amount_cents) {
                    throw new ApiException('partial_payment', self::concept($cargo).' se paga completo: '.PadronService::money($cargo->amount_cents).'.', 422);
                }
            }

            $pago = new Pago;
            if ($id !== null) {
                $pago->id = $id;
            }
            $pago->forceFill([
                'familia_id' => $familia->id,
                'vivienda_id' => $familia->vivienda_id,
                'cobrador_id' => $actor->id,
                'device_id' => $deviceId,
                'folio' => $folio,
                'total_cents' => $cargos->sum('amount_cents'),
                'paid_at' => $paidAt !== null ? now()->parse($paidAt) : now(),
                'cobradores' => $cobradores ?? implode(', ', AjustesService::cobradores($familia->community_id)),
            ])->save();

            foreach ($cargos as $cargo) {
                (new PagoDetalle)->forceFill(['pago_id' => $pago->id, 'cargo_id' => $cargo->id, 'amount_cents' => $cargo->amount_cents])->save();
            }

            AuditLog::record('pago.created', [
                'user_id' => $actor->id,
                'device_id' => $deviceId,
                'entity' => Pago::ENTITY,
                'entity_id' => $pago->id,
                'data' => [
                    'folio' => $pago->folio,
                    'familia' => $familia->name,
                    'total_cents' => $pago->total_cents,
                    'conceptos' => $this->sorted($cargos)->map(fn (Cargo $c) => self::concept($c))->all(),
                ],
            ]);

            return $pago;
        });
    }

    /**
     * Crea (si no existe) el cargo de un periodo futuro para pagarlo por adelantado, respetando el
     * máximo de periodos de la comunidad. Usa la cuota especial del titular o la cuota de ese periodo.
     */
    private function ensureAdvanceCargo(Familia $familia, int $year, int $month): void
    {
        $community = $this->context->runAsSystem(fn () => Community::find($familia->community_id));
        $meses = max(1, (int) $community->periodicidad_meses);
        $now = now();
        $currentStart = Calendario::inicio((int) $now->format('n'), $meses);
        $ahead = ($year - (int) $now->format('Y')) * 12 + ($month - $currentStart);
        if ($month < 1 || $month > 12 || $ahead < 0 || ! Calendario::esInicio($month, $meses)) {
            throw new ApiException('validation_error', 'Periodo por adelantado no válido.', 422);
        }
        if (intdiv($ahead, $meses) > (int) $community->meses_adelanto) {
            throw new ApiException('too_far_ahead', "Solo se pueden pagar {$community->meses_adelanto} periodo(s) por adelantado.", 422);
        }

        $periodoId = CuotasService::periodoId($familia->community_id, $year, $month);
        $cargoId = CuotasService::cargoId($periodoId, $familia->id);
        if (Cargo::whereKey($cargoId)->exists()) {
            return;
        }

        $periodo = Periodo::find($periodoId);
        if ($periodo === null) {
            $cuota = Cuota::forMonth($year, $month)
                ?? throw new ApiException('no_cuota', 'No hay una cuota para '.Periodo::monthLabel($year, $month).'.', 422);
            $periodo = new Periodo;
            $periodo->id = $periodoId;
            $periodo->forceFill(['year' => $year, 'month' => $month, 'meses' => $meses, 'cuota_id' => $cuota->id, 'amount_cents' => $cuota->amount_cents])->save();
        }

        $cargo = new Cargo;
        $cargo->id = $cargoId;
        $cargo->forceFill([
            'periodo_id' => $periodo->id,
            'familia_id' => $familia->id,
            'concept' => Cargo::CUOTA,
            'year' => $year,
            'month' => $month,
            'meses' => $periodo->meses ?? $meses,
            'amount_cents' => $familia->cuota_especial_cents ?? $periodo->amount_cents,
        ])->save();
        app(CuotasPublisher::class)->publish($cargo, 'create');
    }

    /** Estado de cuenta de una familia: cada cargo con su estado (por pagar, pagado, anulado). */
    public function estado(Familia $familia): array
    {
        $cargos = $this->sorted(Cargo::where('familia_id', $familia->id)->get());
        $paid = PagoDetalle::whereIn('cargo_id', $cargos->pluck('id'))
            ->whereHas('pago', fn ($q) => $q->whereNull('voided_at'))->with('pago')->get()->keyBy('cargo_id');

        return $cargos->map(fn (Cargo $c) => [
            'id' => $c->id,
            'concept' => $c->concept,
            'label' => self::concept($c),
            'description' => $c->description,
            'year' => $c->year,
            'month' => $c->month,
            'amount_cents' => $c->amount_cents,
            'status' => $c->voided_at !== null ? 'anulado' : ($paid->has($c->id) ? 'pagado' : 'por_pagar'),
            'folio' => $paid->get($c->id)?->pago->folio,
        ])->values()->all();
    }

    public function publish(Pago $pago, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $pago->community_id,
            'entity' => Pago::ENTITY,
            'entity_id' => $pago->id,
            'op' => $op,
            'payload' => self::present($pago->fresh()),
            'origin_device_id' => $this->originDeviceId,
            'created_at' => now(),
        ])->save();
    }

    public static function present(Pago $pago): array
    {
        return [
            'id' => $pago->id,
            'familia_id' => $pago->familia_id,
            'vivienda_id' => $pago->vivienda_id,
            'cobrador_id' => $pago->cobrador_id,
            'cobrador_name' => $pago->cobrador?->name,
            'folio' => $pago->folio,
            'total_cents' => $pago->total_cents,
            'paid_at' => $pago->paid_at?->toIso8601String(),
            'cobradores' => $pago->cobradores,
            'lines' => $pago->detalles()->get()->map(fn (PagoDetalle $d) => ['cargo_id' => $d->cargo_id, 'amount_cents' => $d->amount_cents])->all(),
            'voided_at' => $pago->voided_at?->toIso8601String(),
            'voided_by' => $pago->voided_by,
            'void_reason' => $pago->void_reason,
            'created_at' => $pago->created_at?->toIso8601String(),
        ];
    }

    /** "Cuota de octubre 2026" o "Multa: No asistió a la faena (octubre 2026)". */
    public static function concept(Cargo $cargo): string
    {
        $month = Calendario::label($cargo->year, $cargo->month, (int) ($cargo->meses ?? 1));

        return $cargo->concept === Cargo::MULTA ? "Multa: {$cargo->description} ({$month})" : "Cuota de {$month}";
    }

    /** @param Collection<int, Cargo> $cargos */
    private function sorted(Collection $cargos): Collection
    {
        return $cargos->sortBy(fn (Cargo $c) => sprintf('%04d%02d%s', $c->year, $c->month, $c->concept === Cargo::CUOTA ? '0' : '1'))->values();
    }

    private function validateFolio(string $folio, ?string $deviceId): string
    {
        $prefix = $deviceId !== null ? Device::whereKey($deviceId)->value('receipt_prefix') : null;
        if ($prefix === null || ! preg_match('/^'.preg_quote($prefix, '/').'-\d{6}$/', $folio)) {
            throw new ApiException('invalid_folio', 'El folio no corresponde a este equipo.', 422);
        }
        if (Pago::where('folio', $folio)->exists()) {
            throw new ApiException('folio_taken', "El folio {$folio} ya se usó.", 409);
        }

        return $folio;
    }

    private function nextWebFolio(): string
    {
        $last = Pago::where('folio', 'like', self::WEB_PREFIX.'-%')->lockForUpdate()->orderByDesc('folio')->value('folio');
        $next = $last === null ? 1 : ((int) substr($last, strlen(self::WEB_PREFIX) + 1)) + 1;

        return sprintf('%s-%06d', self::WEB_PREFIX, $next);
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }
}
