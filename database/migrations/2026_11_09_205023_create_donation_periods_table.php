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
        Schema::create('donation_periods', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->unsignedInteger('goal_cents')->default(0);
            $table->unsignedInteger('covered_cents')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('alter table donation_periods add constraint donation_periods_amounts_not_negative check (goal_cents >= 0 and covered_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_periods');
    }
};
