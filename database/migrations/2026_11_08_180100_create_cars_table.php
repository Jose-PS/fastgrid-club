<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            $table->string('internal_name')->unique();
            $table->string('display_name');
            $table->string('type')->nullable();
            $table->string('era')->nullable();
            $table->string('engine')->nullable();
            $table->jsonb('classes');
            $table->boolean('is_mod')->default(false);
            $table->boolean('active')->default(true);
            $table->boolean('proposable')->default(true);
            $table->timestampTz('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
