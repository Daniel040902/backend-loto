<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lottery_results', function (Blueprint $table) {
            if (!Schema::hasColumn('lottery_results', 'reventado_numero')) {
                $table->string('reventado_numero', 10)->nullable()->after('draw_number');
            }
            if (!Schema::hasColumn('lottery_results', 'bolita_color')) {
                $table->string('bolita_color', 5)->nullable()->after('reventado_numero');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lottery_results', function (Blueprint $table) {
            $table->dropColumn(['reventado_numero', 'bolita_color']);
        });
    }
};
