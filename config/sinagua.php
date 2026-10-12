<?php

return [

    'auth' => [
        // Intentos fallidos antes de bloquear la cuenta (RNF de seguridad).
        'max_attempts' => (int) env('AUTH_MAX_ATTEMPTS', 5),

        // Minutos de bloqueo tras agotar los intentos (decisión del 1 de octubre).
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),

        // Minutos sin usar el token antes de que expire por inactividad (decisión del 1 de octubre).
        'idle_minutes' => (int) env('AUTH_IDLE_MINUTES', 30),

        // Vida máxima de un token aunque se use continuamente.
        'token_lifetime_hours' => (int) env('AUTH_TOKEN_LIFETIME_HOURS', 12),
    ],

];
