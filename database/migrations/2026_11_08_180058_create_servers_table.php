<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('driver', 32);
            $table->string('base_url')->nullable();
            $table->string('credentials_ref')->nullable();
            $table->string('ingame_prefix');
            $table->smallInteger('max_players')->default(24);
            $table->boolean('planning_paused')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
