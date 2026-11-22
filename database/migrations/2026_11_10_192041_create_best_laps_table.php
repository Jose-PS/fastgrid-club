<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('best_laps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lap_id')->nullable()->constrained('laps')->nullOnDelete();
            $table->integer('lap_ms');
            $table->timestampTz('achieved_at', 3);

            $table->unique(['track_id', 'car_id', 'driver_id']);
            $table->index(['track_id', 'car_id', 'lap_ms']);
            $table->index(['lap_ms', 'achieved_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('best_laps');
    }
};
