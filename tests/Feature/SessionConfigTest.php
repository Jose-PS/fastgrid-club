<?php

declare(strict_types=1);

test('sessions last thirty days and renew with activity', function () {
    expect(config('session.lifetime'))->toBe(60 * 24 * 30)
        ->and(config('session.expire_on_close'))->toBeFalse();
});
