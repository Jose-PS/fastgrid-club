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
        Schema::create('schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('effective_from')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('schedule_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_version_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('weekday');
            $table->time('starts_at');
            $table->smallInteger('practice_minutes')->nullable();
            $table->smallInteger('qualifying_minutes')->nullable();
            $table->string('race_duration_type', 8)->nullable();
            $table->smallInteger('race_minutes')->nullable();
            $table->smallInteger('race_laps')->nullable();
            $table->timestampsTz();

            $table->unique(['schedule_version_id', 'weekday', 'starts_at']);
        });

        $now = now();

        $versionId = DB::table('schedule_versions')->insertGetId([
            'effective_from' => '2025-12-31 23:00:00+00',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $empty = [
            'practice_minutes' => null,
            'qualifying_minutes' => null,
            'race_duration_type' => null,
            'race_minutes' => null,
            'race_laps' => null,
        ];

        $segments = [];

        foreach (range(1, 7) as $weekday) {
            $segments[] = array_merge($empty, [
                'schedule_version_id' => $versionId,
                'weekday' => $weekday,
                'starts_at' => '00:00',
                'practice_minutes' => 120,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $segments[] = array_merge($empty, [
            'schedule_version_id' => $versionId,
            'weekday' => 6,
            'starts_at' => '18:00',
            'qualifying_minutes' => 30,
            'race_duration_type' => 'time',
            'race_minutes' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('schedule_segments')->insert($segments);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_segments');
        Schema::dropIfExists('schedule_versions');
    }
};
