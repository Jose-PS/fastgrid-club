<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_session_id')->constrained('server_sessions')->cascadeOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('cycle_index')->nullable();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('position');
            $table->smallInteger('laps_completed');
            $table->integer('total_ms')->nullable();
            $table->integer('gap_ms')->nullable();
            $table->boolean('lapped')->default(false);
            $table->integer('best_lap_ms')->nullable();
            $table->smallInteger('grid_position')->nullable();
            $table->boolean('classified')->default(true);
            $table->string('source');
            $table->timestampTz('created_at', 3);

            $table->unique(['server_session_id', 'driver_id']);
            $table->index(['server_session_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_results');
    }
};
