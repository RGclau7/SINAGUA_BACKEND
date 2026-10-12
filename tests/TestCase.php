<?php

namespace Tests;

use App\Models\Community;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Autentica a un usuario de la comunidad dada (o de una nueva) y fija el contexto
     * para que el código de la prueba pueda crear y leer modelos de esa comunidad.
     */
    protected function actingAsMemberOf(?Community $community = null): User
    {
        $community ??= Community::factory()->create();
        $user = User::factory()->forCommunity($community)->create();
        Sanctum::actingAs($user);
        $this->useCommunity($community);

        return $user;
    }

    protected function useCommunity(?Community $community): void
    {
        app(CurrentCommunity::class)->set($community?->id);
    }

    protected function usesPostgres(): bool
    {
        return config('database.default') === 'pgsql';
    }
}
