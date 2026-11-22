<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->constrained();
            $table->char('signature', 40);
            $table->timestampTz('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['week_id', 'signature']);
            $table->index(['week_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
