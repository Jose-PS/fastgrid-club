<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('steam_id')->unique();
            $table->string('last_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('hidden')->default(false);
            $table->timestampTz('first_seen_at', 3);
            $table->timestampTz('last_seen_at', 3);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
