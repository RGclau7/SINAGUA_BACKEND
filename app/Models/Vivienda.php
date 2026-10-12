<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Domicilio de la comunidad. Puede tener varias familias (la cuota es por familia).
 */
#[Fillable(['address', 'neighborhood', 'reference'])]
class Vivienda extends Model
{
    use BelongsToCommunity, HasUuids;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function familias(): HasMany
    {
        return $this->hasMany(Familia::class);
    }

    /** Texto para mostrar: "Calle Hidalgo 10, Barrio Alto". */
    public function label(): string
    {
        return $this->neighborhood ? "{$this->address}, {$this->neighborhood}" : $this->address;
    }

    /**
     * Llave para reconocer el mismo domicilio escrito de otra forma: sin acentos, sin
     * signos y sin espacios de más ("C. Hidalgo #10" y "c hidalgo 10" son iguales).
     */
    public static function addressKey(string $address, ?string $neighborhood): string
    {
        $text = mb_strtolower(trim($address.' '.($neighborhood ?? '')));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
