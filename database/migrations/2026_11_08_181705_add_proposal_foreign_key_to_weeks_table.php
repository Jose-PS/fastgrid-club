<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weeks', function (Blueprint $table) {
            $table->foreign('proposal_id')->references('id')->on('proposals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('weeks', function (Blueprint $table) {
            $table->dropForeign(['proposal_id']);
        });
    }
};
