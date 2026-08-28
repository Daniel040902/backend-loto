<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'preferred_country')) {
            Schema::table('users', function ($table) {
                $table->dropColumn('preferred_country');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->string('preferred_country')->nullable();
        });
    }
};
