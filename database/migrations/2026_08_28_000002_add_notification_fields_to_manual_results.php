<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_results', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('official_checked_at')->nullable();
            $table->string('status', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('manual_results', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'official_checked_at', 'status']);
        });
    }
};
