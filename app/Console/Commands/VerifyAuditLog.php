<?php

namespace App\Console\Commands;

use App\Audit\AuditChain;
use App\Models\Community;
use Illuminate\Console\Command;

class VerifyAuditLog extends Command
{
    protected $signature = 'sinagua:verificar-bitacora';

    protected $description = 'Comprueba la cadena de huellas de la bitácora de cada comunidad';

    public function handle(): int
    {
        $failed = false;
        $chains = [null => 'Sistema (superadministrador)'] + Community::query()->pluck('name', 'id')->all();

        foreach ($chains as $id => $name) {
            $result = AuditChain::verify($id === '' ? null : $id);
            if ($result['ok']) {
                $this->info("✔ {$name}: {$result['checked']} registros íntegros");
            } else {
                $failed = true;
                $this->error("✘ {$name}: la cadena se rompe en el registro #{$result['broken_at']}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
