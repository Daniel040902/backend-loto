<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Revoca el token Sanctum expuesto (id 40 / hash de la parte secreta).
        // Solo se almacena el hash sha256, nunca el secreto en claro.
        DB::table('personal_access_tokens')
            ->where('id', 40)
            ->orWhere('token', '64a9a2c5001948360d24c4dca0d2845b59a335b843f4c98b4b96ec6e2a16cb30')
            ->delete();
    }

    public function down(): void
    {
    }
};
