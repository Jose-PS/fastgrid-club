<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->string('block_key')->nullable();
            $table->string('block')->nullable();
            $table->smallInteger('attempt');
            $table->string('status');
            $table->text('error')->nullable();
            $table->jsonb('snapshot');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['server_id', 'block_key', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_applications');
    }
};
