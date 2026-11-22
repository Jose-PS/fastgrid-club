<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_cursors', function (Blueprint $table) {
            $table->unsignedBigInteger('last_event_id')->nullable()->after('window_hashes');
        });
    }

    public function down(): void
    {
        Schema::table('log_cursors', function (Blueprint $table) {
            $table->dropColumn('last_event_id');
        });
    }
};
