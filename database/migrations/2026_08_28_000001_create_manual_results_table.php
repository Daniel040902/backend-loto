<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->date('draw_date');
            $table->string('draw_time', 20)->nullable();
            $table->json('winning_numbers');
            $table->json('prizes')->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestamps();

            $table->unique(['game_id', 'draw_date', 'draw_time'], 'unique_manual_per_game_date_time');
            $table->index(['country_id', 'draw_date']);
            $table->index(['game_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_results');
    }
};
