<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('steam_id', 17)->nullable();
            $table->timestampsTz();

            $table->unique(['week_id', 'steam_id']);
            $table->index('proposal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
