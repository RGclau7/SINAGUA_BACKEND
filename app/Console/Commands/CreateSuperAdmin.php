<?php

namespace App\Console\Commands;

use App\Models\Roles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class CreateSuperAdmin extends Command
{
    protected $signature = 'sinagua:superadmin {email} {name}';

    protected $description = 'Crea un superadministrador (gestiona comunidades, no ve sus movimientos)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->secret('Contraseña (mínimo 10 caracteres)');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'min:10']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => $password]);
        $user->assignRole(Role::findOrCreate(Roles::SUPERADMIN, 'web'));

        $this->info("Superadministrador creado: {$user->email}");

        return self::SUCCESS;
    }
}
