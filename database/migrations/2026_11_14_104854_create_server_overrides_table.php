<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('plan');
            $table->timestampTz('until')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['server_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_overrides');
    }
};
