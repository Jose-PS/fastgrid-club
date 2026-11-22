<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_period_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->unsignedInteger('old_cents');
            $table->unsignedInteger('new_cents');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_changes');
    }
};
