<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_segments', function (Blueprint $table) {
            $table->smallInteger('start_hour')->nullable();
            $table->string('weather', 16)->nullable();
        });

        DB::table('schedule_segments')->update(['start_hour' => 14, 'weather' => 'clear']);

        Schema::table('schedule_segments', function (Blueprint $table) {
            $table->smallInteger('start_hour')->nullable(false)->change();
            $table->string('weather', 16)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_segments', function (Blueprint $table) {
            $table->dropColumn(['start_hour', 'weather']);
        });
    }
};
