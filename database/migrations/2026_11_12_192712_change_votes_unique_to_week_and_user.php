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
        $this->ensureNoDuplicates('user_id');

        Schema::table('votes', function (Blueprint $table) {
            $table->dropUnique(['week_id', 'steam_id']);
            $table->unique(['week_id', 'user_id']);
            $table->index('steam_id');
        });
    }

    public function down(): void
    {
        $this->ensureNoDuplicates('steam_id');

        Schema::table('votes', function (Blueprint $table) {
            $table->dropIndex(['steam_id']);
            $table->dropUnique(['week_id', 'user_id']);
            $table->unique(['week_id', 'steam_id']);
        });
    }

    private function ensureNoDuplicates(string $column): void
    {
        $duplicates = DB::query()
            ->fromSub(
                DB::table('votes')
                    ->select('week_id', $column)
                    ->whereNotNull($column)
                    ->groupBy('week_id', $column)
                    ->havingRaw('count(*) > 1'),
                'duplicated_votes',
            )
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException("votes has {$duplicates} duplicated (week_id, {$column}) pairs; resolve them before changing the unique index.");
        }
    }
};
