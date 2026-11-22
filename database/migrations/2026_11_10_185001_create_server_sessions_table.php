<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestampTz('started_at', 3);
            $table->timestampTz('ended_at', 3)->nullable();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('cycle_index')->nullable();
            $table->string('source_line_hash');

            $table->unique(['server_id', 'source_line_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_sessions');
    }
};
