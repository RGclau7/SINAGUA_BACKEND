<?php

namespace App\Movements;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Movimiento de dinero que solo se anexa y, si hace falta, se anula con motivo (RF23, CDU23).
 * Nunca se borra ni se modifica: lo único que cambia después de crearlo es la anulación.
 *
 * La tabla necesita las columnas de `$table->voidable()` (voided_at, voided_by, void_reason)
 * y el modelo debe implementar movementEntity() con su nombre en la sincronización.
 */
trait Voidable
{
    public const VOID_FIELDS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    public const MIN_REASON_LENGTH = 5;

    abstract public function movementEntity(): string;

    public static function bootVoidable(): void
    {
        static::updating(function (Model $model) {
            $changed = array_diff(array_keys($model->getDirty()), self::VOID_FIELDS);
            if ($changed !== []) {
                throw new LogicException('Un movimiento no se modifica; solo puede anularse con motivo.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un movimiento no se borra; solo puede anularse con motivo.'));
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Anula el movimiento. Quien llama ya verificó el permiso movimientos.anular.
     *
     * @throws ApiException reason_required | already_voided
     */
    public function voidWith(User $actor, ?string $reason, ?string $deviceId = null): static
    {
        $reason = trim(preg_replace('/\s+/', ' ', (string) $reason));
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new ApiException('reason_required', 'El motivo de la anulación es obligatorio (mínimo 5 caracteres).', 422);
        }
        if ($this->isVoided()) {
            throw new ApiException('already_voided', 'Este movimiento ya está anulado.', 409);
        }

        $this->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();

        AuditLog::record('movement.voided', [
            'user_id' => $actor->id,
            'device_id' => $deviceId,
            'entity' => $this->movementEntity(),
            'entity_id' => $this->getKey(),
            'data' => ['reason' => $reason],
        ]);

        // Los módulos de cobranza, entregas y cortes recalculan adeudos y cortes al escucharlo.
        event(new MovementVoided($this, $actor, $reason));

        return $this;
    }
}
