<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CommunityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentCommunity::class);
        if ($context->isSystem()) {
            return;
        }

        $id = $context->id();
        if ($id === null) {
            // Sin comunidad no se ve nada: se falla cerrado.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('community_id'), $id);
    }
}
