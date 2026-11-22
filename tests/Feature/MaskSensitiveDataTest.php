<?php

declare(strict_types=1);

use App\Logging\MaskSensitiveData;
use App\Logging\MaskSensitiveDataTap;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function maskedRecord(array $context, array $extra = []): LogRecord
{
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'message', $context, $extra);

    return (new MaskSensitiveData)($record);
}

test('sensitive keys are masked recursively and case insensitively', function () {
    $record = maskedRecord([
        'password' => 'a',
        'driver_password' => 'b',
        'Authorization' => 'Bearer x',
        'nested' => ['API_TOKEN' => 'c', 'deep' => ['Set-Cookie' => 'd', 'client_secret' => 'e']],
    ], ['cookie' => 'f']);

    expect($record->context['password'])->toBe('[masked]')
        ->and($record->context['driver_password'])->toBe('[masked]')
        ->and($record->context['Authorization'])->toBe('[masked]')
        ->and($record->context['nested']['API_TOKEN'])->toBe('[masked]')
        ->and($record->context['nested']['deep']['Set-Cookie'])->toBe('[masked]')
        ->and($record->context['nested']['deep']['client_secret'])->toBe('[masked]')
        ->and($record->extra['cookie'])->toBe('[masked]');
});

test('other keys are left intact', function () {
    $record = maskedRecord(['user_id' => 5, 'nested' => ['name' => 'Ana']], ['request_id' => 'abc']);

    expect($record->context)->toBe(['user_id' => 5, 'nested' => ['name' => 'Ana']])
        ->and($record->extra)->toBe(['request_id' => 'abc']);
});

test('every configured channel except emergency applies the tap', function () {
    foreach (config('logging.channels') as $name => $channel) {
        if ($name === 'emergency') {
            continue;
        }

        expect($channel['tap'] ?? [])->toContain(MaskSensitiveDataTap::class);
    }

    expect(config('logging.channels.stderr.formatter'))->toBe(JsonFormatter::class);
});

test('email keys are masked at any depth', function () {
    $record = maskedRecord([
        'email' => 'ana@example.test',
        'User_Email' => 'ana@example.test',
        'mail_to' => 'ana@example.test',
        'nested' => ['recipient_email' => 'ana@example.test'],
    ], ['email' => 'ana@example.test']);

    expect($record->context['email'])->toBe('[masked]')
        ->and($record->context['User_Email'])->toBe('[masked]')
        ->and($record->context['mail_to'])->toBe('[masked]')
        ->and($record->context['nested']['recipient_email'])->toBe('[masked]')
        ->and($record->extra['email'])->toBe('[masked]');
});

test('steam id keys are masked', function () {
    $record = maskedRecord(['steam_id' => '76561198000000001', 'nested' => ['steamid' => '76561198000000002']]);

    expect($record->context['steam_id'])->toBe('[masked]')
        ->and($record->context['nested']['steamid'])->toBe('[masked]');
});
