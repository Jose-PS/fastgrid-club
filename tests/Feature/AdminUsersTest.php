<?php

declare(strict_types=1);

use App\Models\Driver;
use App\Models\Lap;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the search filters users by name and by email', function () {
    $admin = User::factory()->admin()->create(['name' => 'Zed Admin', 'email' => 'zed@example.com']);
    User::factory()->create(['name' => 'Marta Ruiz', 'email' => 'marta@example.com']);
    User::factory()->create(['name' => 'Pablo Gil', 'email' => 'pablo@example.com']);

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'marta']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users')
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Marta Ruiz')
            ->where('filters.search', 'marta'));

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'PABLO@']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Pablo Gil'));
});

test('a non admin user gets a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertNotFound();
});

test('the aggregated counters are correct for a user with data', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->withVerifiedEmail()->withSteam()->withGoogle()->create();
    User::factory()->create();

    $user->passkeys()->create(['name' => 'Portátil', 'credential_id' => Str::random(32), 'credential' => []]);
    $user->passkeys()->create(['name' => 'Móvil', 'credential_id' => Str::random(32), 'credential' => []]);

    $proposal = Proposal::factory()->create(['user_id' => $user->id]);
    Proposal::factory()->create(['user_id' => $user->id]);
    Vote::factory()->create(['proposal_id' => $proposal->id, 'user_id' => $user->id]);

    $driver = Driver::factory()->create(['user_id' => $user->id, 'last_name' => 'Speedy']);
    Lap::factory()->count(3)->create(['driver_id' => $driver->id]);
    Lap::factory()->create();

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => $user->email]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.passkeys_count', 2)
            ->where('users.data.0.proposals_count', 2)
            ->where('users.data.0.votes_count', 1)
            ->where('users.data.0.laps_count', 3)
            ->where('users.data.0.driver_names', 'Speedy')
            ->where('users.data.0.has_google', true)
            ->where('users.data.0.has_steam', true)
            ->where('users.data.0.email_verified', true));
});
