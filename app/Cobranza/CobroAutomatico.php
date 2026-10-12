<?php

namespace App\Cobranza;

use App\Comunidad\Calendario;
use App\Cuotas\CuotasPublisher;
use App\Cuotas\CuotasService;
use App\Models\Cargo;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\Periodo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Lo que pasa solo cada mes, sin que nadie pulse un botón:
 * - la cuota del mes en curso se carga a cada titular activo (si hay cuota vigente);
 * - a cada periodo no pagado después de su último día de pago se le suma la multa fija de la
 *   comunidad, una por periodo atrasado.
 * Corre al sincronizar y al cobrar (a lo más una vez por hora y comunidad) y con el comando
 * diario `sinagua:cobro-automatico`. Los ids son fijos (UUID v5): nunca se duplica nada.
 */
class CobroAutomatico
{
    public function __construct(
        private readonly CuotasService $cuotas,
        private readonly CuotasPublisher $publisher,
    ) {}

    /** Corre si no ha corrido en la última hora para esta comunidad. */
    public function runIfDue(Community $community): void
    {
        if (Cache::add("cobro-automatico:{$community->id}:".now()->format('YmdH'), true, 3600)) {
            $this->run($community);
        }
    }

    public function run(Community $community): void
    {
        $now = CarbonImmutable::now();

        // Cuota del periodo en curso (cada mes, o cada N meses si así cobra la comunidad).
        $start = Calendario::inicio($now->month, (int) $community->periodicidad_meses);
        if (Cuota::forMonth($now->year, $start) !== null) {
            $this->cuotas->generate(null, $now->year, $start);
        }

        // Multas por meses no pagados a tiempo.
        $multa = (int) $community->multa_cents;
        if ($multa <= 0) {
            return;
        }

        $vencidas = CobranzaService::unpaid()
            ->where('concept', Cargo::CUOTA)
            ->whereNotIn('id', Cargo::query()->select('origen_cargo_id')->whereNotNull('origen_cargo_id'))
            ->get()
            ->filter(fn (Cargo $c) => $now->greaterThan(Calendario::limite($community, $c->year, $c->month)));

        foreach ($vencidas as $cargo) {
            DB::transaction(function () use ($cargo, $multa) {
                $id = self::multaId($cargo->id);
                if (Cargo::whereKey($id)->exists()) {
                    return;
                }
                $nueva = new Cargo;
                $nueva->id = $id;
                $nueva->forceFill([
                    'familia_id' => $cargo->familia_id,
                    'concept' => Cargo::MULTA,
                    'description' => 'No pagó '.Calendario::label($cargo->year, $cargo->month, (int) $cargo->meses).' a tiempo',
                    'origen_cargo_id' => $cargo->id,
                    'year' => $cargo->year,
                    'month' => $cargo->month,
                    'amount_cents' => $multa,
                ])->save();
                $this->publisher->publish($nueva, 'create');
            });
        }
    }

    public static function multaId(string $cargoId): string
    {
        return Uuid::uuid5(CuotasService::UUID_NAMESPACE, "multa:{$cargoId}")->toString();
    }
}
