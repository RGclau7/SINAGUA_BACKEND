<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro completo: datos de la persona (usuarios) y de la comunidad.
     * Nullable en la base para cuentas creadas antes; la API los exige en altas nuevas.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('given_names', 120)->nullable()->after('name');
            $table->string('paternal_surname', 80)->nullable()->after('given_names');
            $table->string('maternal_surname', 80)->nullable()->after('paternal_surname');
            $table->string('phone', 20)->nullable()->after('maternal_surname');
            $table->string('address', 255)->nullable()->after('phone');
        });

        Schema::table('communities', function (Blueprint $table) {
            $table->string('state', 80)->nullable()->after('name');
            $table->string('municipality', 120)->nullable()->after('state');
            $table->string('locality', 255)->nullable()->after('municipality');
            $table->string('contact_phone', 20)->nullable()->after('locality');
            $table->string('contact_email', 190)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['given_names', 'paternal_surname', 'maternal_surname', 'phone', 'address']);
        });
        Schema::table('communities', function (Blueprint $table) {
            $table->dropColumn(['state', 'municipality', 'locality', 'contact_phone', 'contact_email']);
        });
    }
};
