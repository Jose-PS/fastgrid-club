<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->jsonb('summary');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->timestampsTz();

            $table->index(['server_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_syncs');
    }
};
