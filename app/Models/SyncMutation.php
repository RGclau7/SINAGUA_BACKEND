<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['mutation_id', 'device_id', 'user_id', 'entity', 'entity_id', 'op', 'status', 'reason_code', 'reason_message', 'occurred_at', 'received_at'])]
class SyncMutation extends Model
{
    use BelongsToCommunity;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $primaryKey = 'mutation_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
