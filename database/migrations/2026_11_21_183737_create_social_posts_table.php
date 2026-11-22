<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('key')->unique();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('week_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('text');
            $table->jsonb('data');
            $table->string('image_path')->nullable();
            $table->jsonb('deliveries')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
