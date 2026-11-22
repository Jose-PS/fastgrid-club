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
        Schema::create('weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('max_players')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('voting_closes_at');
            $table->string('status', 16);
            $table->foreignId('track_id')->nullable()->constrained('tracks');
            $table->string('config_source', 16)->nullable();
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['server_id', 'starts_at']);
        });

        DB::statement('alter table weeks add constraint weeks_voting_closes_before_start check (voting_closes_at < starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('weeks');
    }
};
