<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ServerDriverType;
use App\Models\Server;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    public function run(): void
    {
        Server::query()->updateOrCreate(
            ['slug' => 'main'],
            [
                'name' => 'FastGrid',
                'ingame_prefix' => 'FastGrid',
                'max_players' => 24,
                'credentials_ref' => 'main',
                'driver' => ServerDriverType::Fake,
                'base_url' => null,
            ],
        );
    }
}
