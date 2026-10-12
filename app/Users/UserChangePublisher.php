<?php

namespace App\Users;

use App\Comunidad\AjustesService;
use App\Models\Community;
use App\Models\SyncChange;
use App\Models\User;
use App\Tenancy\CurrentCommunity;

/**
 * Publica en el feed de sincronización los cambios de usuarios hechos fuera de /sync/push
 * (API REST, alta de comunidad), para que los equipos de la comunidad los descarguen.
 */
class UserChangePublisher
{
    public const ENTITY = 'usuarios';

    public function publish(User $user, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $user->community_id,
            'entity' => self::ENTITY,
            'entity_id' => $user->id,
            'op' => $op,
            'payload' => UserService::present($user->fresh()),
            'created_at' => now(),
        ])->save();

        // Los recibos llevan los nombres de los cobradores: se avisa a los equipos.
        if ($user->community_id !== null) {
            $community = app(CurrentCommunity::class)->runAsSystem(fn () => Community::find($user->community_id));
            app(AjustesService::class)->publish($community);
        }
    }
}
