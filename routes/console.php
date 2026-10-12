<?php

use App\Cobranza\CobroAutomatico;
use App\Models\Community;
use App\Tenancy\CurrentCommunity;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cuota del mes y multas por meses no pagados a tiempo, para todas las comunidades.
Artisan::command('sinagua:cobro-automatico', function () {
    $context = app(CurrentCommunity::class);
    $communities = $context->runAsSystem(fn () => Community::where('is_active', true)->get());
    foreach ($communities as $community) {
        $context->set($community->id);
        app(CobroAutomatico::class)->run($community);
        $this->info("{$community->name}: al día.");
    }
    $context->set(null);
})->purpose('Genera la cuota del mes y las multas vencidas de cada comunidad');

Schedule::command('sinagua:cobro-automatico')->dailyAt('00:30');
