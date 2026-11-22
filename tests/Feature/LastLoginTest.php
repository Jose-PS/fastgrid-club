<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('a login records the last login time without touching updated_at', function () {
    $this->travelTo(now()->subDay());
    $user = User::factory()->create();
    $updatedAt = $user->updated_at->toIso8601String();
    $this->travelBack();

    expect($user->last_login_at)->toBeNull();

    Auth::guard('web')->login($user);

    $fresh = $user->fresh();

    expect($fresh->last_login_at)->not->toBeNull()
        ->and($fresh->last_login_at->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and($fresh->updated_at->toIso8601String())->toBe($updatedAt);
});
