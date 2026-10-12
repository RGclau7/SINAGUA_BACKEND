<?php

namespace App\Audit;

/**
 * La bitácora nunca guarda contraseñas ni sus hashes, aunque vengan en un payload.
 */
final class AuditSanitizer
{
    private const SECRET_KEYS = ['password', 'password_hash', 'password_confirmation', 'token', 'remember_token'];

    public static function clean(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                continue;
            }
            $clean[$key] = self::clean($value);
        }

        return $clean;
    }
}
