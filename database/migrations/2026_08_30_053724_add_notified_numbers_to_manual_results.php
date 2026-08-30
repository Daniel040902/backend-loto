<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_results', function (Blueprint $table) {
            $table->json('notified_numbers')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('manual_results', function (Blueprint $table) {
            $table->dropColumn('notified_numbers');
        });
    }
};
