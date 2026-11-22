<?php

declare(strict_types=1);

use App\Support\RequiredEnvironment;

function completeEnvironment(array $overrides = []): array
{
    $values = array_fill_keys(RequiredEnvironment::REQUIRED, 'valor');

    return array_merge($values, ['APP_ENV' => 'production', 'APP_DEBUG' => false], $overrides);
}

function environmentFrom(array $values): RequiredEnvironment
{
    return new RequiredEnvironment(fn (string $name): mixed => $values[$name] ?? null);
}

test('a complete environment has no problems', function () {
    expect(environmentFrom(completeEnvironment())->problems())->toBe([]);
});

test('it reports every missing required variable', function () {
    $values = completeEnvironment(['APP_KEY' => null, 'DB_PASSWORD' => '']);
    unset($values['REDIS_PASSWORD']);

    expect(environmentFrom($values)->missing())->toBe(['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD']);
});

test('it names the missing variable in the message', function () {
    $problems = environmentFrom(completeEnvironment(['SERVER_PASS' => null]))->problems();

    expect($problems)->toBe(['Falta la variable de entorno obligatoria SERVER_PASS.']);
});

test('it rejects debug mode and a non production environment', function () {
    $problems = environmentFrom(completeEnvironment(['APP_DEBUG' => true, 'APP_ENV' => 'local']))->problems();

    expect($problems)->toBe([
        'APP_DEBUG debe ser false en producción.',
        'APP_ENV debe ser production.',
    ]);
});

test('it rejects a mail scheme other than smtp or smtps', function () {
    $problems = environmentFrom(completeEnvironment(['MAIL_SCHEME' => 'tls']))->problems();

    expect($problems)->toBe(['MAIL_SCHEME solo admite smtp o smtps, no «tls».']);
});

test('it accepts smtp and smtps as mail schemes', function () {
    expect(environmentFrom(completeEnvironment(['MAIL_SCHEME' => 'smtp']))->problems())->toBe([])
        ->and(environmentFrom(completeEnvironment(['MAIL_SCHEME' => 'smtps']))->problems())->toBe([]);
});

test('the command succeeds with a complete environment', function () {
    $this->app->instance(RequiredEnvironment::class, environmentFrom(completeEnvironment()));

    $this->artisan('env:check')->expectsOutput('Entorno completo.')->assertSuccessful();
});

test('the command fails with a clear message when a variable is missing', function () {
    $this->app->instance(RequiredEnvironment::class, environmentFrom(completeEnvironment(['GOOGLE_CLIENT_ID' => null])));

    $this->artisan('env:check')
        ->expectsOutputToContain('Falta la variable de entorno obligatoria GOOGLE_CLIENT_ID.')
        ->expectsOutputToContain('El contenedor no arranca')
        ->assertFailed();
});
