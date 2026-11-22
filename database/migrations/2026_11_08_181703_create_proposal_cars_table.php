<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_cars', function (Blueprint $table) {
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained();

            $table->unique(['proposal_id', 'car_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_cars');
    }
};
