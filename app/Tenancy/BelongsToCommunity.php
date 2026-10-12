<?php

namespace App\Tenancy;

use App\Models\Community;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Para todo modelo de negocio: filtra por la comunidad actual y la asigna al crear.
 * community_id no debe ser fillable; así nunca llega del cliente.
 */
trait BelongsToCommunity
{
    public static function bootBelongsToCommunity(): void
    {
        static::addGlobalScope(new CommunityScope);

        static::creating(function (Model $model) {
            $context = app(CurrentCommunity::class);

            if ($context->isSystem()) {
                if (empty($model->community_id) && ! $model->communityIsOptional()) {
                    throw new LogicException('Una operación del sistema debe indicar la comunidad del registro.');
                }

                return;
            }

            $current = $context->id() ?? throw new LogicException('No hay comunidad activa para crear '.$model::class.'.');

            if (! empty($model->community_id) && $model->community_id !== $current) {
                throw new LogicException('No se puede crear un registro en otra comunidad.');
            }

            $model->community_id = $current;
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('community_id')) {
                throw new LogicException('Un registro no puede cambiar de comunidad.');
            }
        });
    }

    /**
     * Solo en modo sistema y solo para modelos que lo permitan (bitácora de eventos
     * del superadministrador) puede crearse un registro sin comunidad.
     */
    public function communityIsOptional(): bool
    {
        return false;
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
