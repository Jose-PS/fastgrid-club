<?php

declare(strict_types=1);

use App\Models\LoginLink;

test('expired login links are pruned', function () {
    $expired = LoginLink::factory()->expired()->create();
    $valid = LoginLink::factory()->create();

    $this->artisan('model:prune', ['--model' => [LoginLink::class]])->assertSuccessful();

    expect(LoginLink::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(LoginLink::query()->whereKey($valid->id)->exists())->toBeTrue();
});
