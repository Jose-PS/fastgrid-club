<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16);
            $table->string('token');
            $table->string('display');
            $table->string('track');
            $table->string('layout');
            $table->string('event_name');
            $table->integer('length_m')->nullable();
            $table->smallInteger('max_pit_slot');
            $table->foreignId('paired_track_id')->nullable()->constrained('tracks')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->boolean('proposable')->default(true);
            $table->timestampTz('synced_at')->nullable();

            $table->unique(['mode', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
