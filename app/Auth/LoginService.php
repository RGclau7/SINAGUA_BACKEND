<?php

namespace App\Auth;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Inicio de sesión con bloqueo tras N intentos fallidos.
 *
 * Correo inexistente y contraseña incorrecta responden igual (mismo código y mismo
 * bloqueo), para no revelar qué correos existen.
 */
class LoginService
{
    public function __construct(private readonly CurrentCommunity $context) {}

    /**
     * @return array{0: User, 1: NewAccessToken}
     *
     * @throws ApiException
     */
    public function attempt(string $email, string $password, ?string $deviceId, ?string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->failUnknownEmail($email, $password);
        }

        if ($user->locked_until?->isFuture()) {
            throw $this->locked($user->locked_until->diffInSeconds(now(), true));
        }

        if (! Hash::check($password, $user->password)) {
            $this->failForUser($user, $ip);
        }

        if (! $user->is_active) {
            $this->audit($user, 'auth.rejected_inactive', ['ip' => $ip]);
            throw new ApiException('account_inactive', 'La cuenta está desactivada. Consulte al administrador.', 403);
        }
        if ($user->community_id !== null && ! $user->community?->is_active) {
            throw new ApiException('community_inactive', 'La comunidad está desactivada.', 403);
        }
        // El superadministrador no pertenece a ninguna comunidad: puede entrar en cualquier equipo
        // (no ve ni sincroniza datos de comunidades).
        if ($deviceId !== null && $user->community_id !== null) {
            $this->assertDeviceUsable($user, $deviceId);
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()]);
        // Contraseñas creadas en el escritorio llegan con su propio costo de bcrypt.
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        // El acceso no cuenta como edición del usuario (updated_at es su versión para sincronizar).
        User::withoutTimestamps(fn () => $user->save());

        $token = $user->createToken(
            name: $deviceId !== null ? "device:{$deviceId}" : 'api',
            expiresAt: now()->addHours(config('sinagua.auth.token_lifetime_hours')),
        );

        $this->audit($user, 'auth.login', ['ip' => $ip, 'device_id' => $deviceId]);

        return [$user, $token];
    }

    private function failForUser(User $user, ?string $ip): never
    {
        $max = config('sinagua.auth.max_attempts');

        // Un bloqueo vencido empieza un conteo nuevo.
        $attempts = $user->locked_until !== null ? 1 : $user->failed_login_attempts + 1;
        $lockedUntil = $attempts >= $max ? now()->addMinutes(config('sinagua.auth.lockout_minutes')) : null;

        $user->forceFill(['failed_login_attempts' => $attempts, 'locked_until' => $lockedUntil]);
        User::withoutTimestamps(fn () => $user->save());

        if ($lockedUntil !== null) {
            $this->audit($user, 'auth.locked', ['ip' => $ip, 'until' => $lockedUntil->toIso8601String()]);
            throw $this->locked(config('sinagua.auth.lockout_minutes') * 60);
        }

        $this->audit($user, 'auth.failed', ['ip' => $ip, 'attempt' => $attempts]);
        throw $this->invalid();
    }

    private function failUnknownEmail(string $email, string $password): never
    {
        // Mismo costo que verificar una contraseña real.
        static $dummyHash;
        $dummyHash ??= Hash::make(Str::random(40));
        Hash::check($password, $dummyHash);

        $key = 'login:'.sha1($email);
        $max = config('sinagua.auth.max_attempts');
        $decay = config('sinagua.auth.lockout_minutes') * 60;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw $this->locked(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $decay);

        throw RateLimiter::tooManyAttempts($key, $max) ? $this->locked($decay) : $this->invalid();
    }

    /**
     * Una cuenta entra desde cualquier equipo. Si el equipo era de otra comunidad, la app de
     * escritorio borra sus datos locales y se registra de nuevo con otro identificador, así que
     * aquí solo se revisa que un equipo de su propia comunidad no esté desactivado.
     */
    private function assertDeviceUsable(User $user, string $deviceId): void
    {
        $device = $this->context->runAsSystem(fn () => Device::find($deviceId));
        if ($device !== null && $device->community_id === $user->community_id && ! $device->is_active) {
            throw new ApiException('device_inactive', 'El equipo fue desactivado por el administrador.', 403);
        }
    }

    private function invalid(): ApiException
    {
        return new ApiException('invalid_credentials', 'Correo o contraseña incorrectos.', 401);
    }

    private function locked(int $seconds): ApiException
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return new ApiException(
            'account_locked',
            "Demasiados intentos fallidos. Intente de nuevo en {$minutes} min.",
            423,
            ['retry_after_seconds' => $seconds],
        );
    }

    private function audit(User $user, string $action, array $data): void
    {
        $this->context->runAsSystem(fn () => AuditLog::record($action, [
            'community_id' => $user->community_id,
            'user_id' => $user->id,
            'entity' => 'users',
            'entity_id' => $user->id,
            'data' => $data,
        ]));
    }
}
