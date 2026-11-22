<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_default_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_title')->nullable();
            $table->timestamps();
        });

        Schema::create('server_default_config_cars', function (Blueprint $table) {
            $table->foreignId('server_default_config_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained();

            $table->unique(['server_default_config_id', 'car_id'], 'server_default_config_cars_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_default_config_cars');
        Schema::dropIfExists('server_default_configs');
    }
};
