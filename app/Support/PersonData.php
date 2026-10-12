<?php

namespace App\Support;

/**
 * Reglas y normalización de los datos de registro de una persona y de teléfonos (México, 10 dígitos).
 */
final class PersonData
{
    public const PHONE_PATTERN = '/^\d{10}$/';

    /** Quita espacios, guiones, paréntesis y el prefijo +52. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    public static function fullName(string $givenNames, string $paternal, ?string $maternal): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$givenNames} {$paternal} ".($maternal ?? '')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $required, string $prefix = ''): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            "{$prefix}given_names" => [$req, 'string', 'max:120'],
            "{$prefix}paternal_surname" => [$req, 'string', 'max:80'],
            "{$prefix}maternal_surname" => ['sometimes', 'nullable', 'string', 'max:80'],
            "{$prefix}phone" => [$req, 'string', 'regex:'.self::PHONE_PATTERN],
            "{$prefix}address" => [$req, 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        return [
            "{$prefix}given_names.required" => 'El nombre es obligatorio.',
            "{$prefix}paternal_surname.required" => 'El apellido paterno es obligatorio.',
            "{$prefix}phone.required" => 'El teléfono es obligatorio.',
            "{$prefix}phone.regex" => 'El teléfono debe tener 10 dígitos.',
            "{$prefix}address.required" => 'La dirección es obligatoria.',
            "{$prefix}address.min" => 'Escriba una dirección completa.',
        ];
    }

    /** Normaliza el teléfono dentro de los datos recibidos (antes de validar). */
    public static function prepare(array $data, string $key = 'phone'): array
    {
        if (array_key_exists($key, $data) && is_string($data[$key])) {
            $data[$key] = self::normalizePhone($data[$key]);
        }
        foreach (['given_names', 'paternal_surname', 'maternal_surname', 'address'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim(preg_replace('/\s+/', ' ', $data[$field]));
                if ($data[$field] === '' && $field === 'maternal_surname') {
                    $data[$field] = null;
                }
            }
        }

        return $data;
    }
}
