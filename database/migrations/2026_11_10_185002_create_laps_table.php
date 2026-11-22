<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_session_id')->nullable()->constrained('server_sessions')->nullOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('lap_ms');
            $table->smallInteger('lap_number')->nullable();
            $table->boolean('is_valid')->nullable();
            $table->timestampTz('recorded_at', 3);
            $table->string('source_hash');

            $table->unique(['server_id', 'source_hash']);
            $table->index(['track_id', 'car_id', 'lap_ms']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laps');
    }
};
