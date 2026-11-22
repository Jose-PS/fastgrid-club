<?php

declare(strict_types=1);

use App\Integration\Server\Log\Events\CarConnected;
use App\Integration\Server\Log\Events\CarCreated;
use App\Integration\Server\Log\Events\CarDestroyed;
use App\Integration\Server\Log\Events\DriverNamed;
use App\Integration\Server\Log\Events\LapCompleted;
use App\Integration\Server\Log\Events\LapRejected;
use App\Integration\Server\Log\Events\PlayersUpdated;
use App\Integration\Server\Log\Events\ServerRestarted;
use App\Integration\Server\Log\Events\SessionCreated;
use App\Integration\Server\Log\Events\SessionPhase;
use App\Integration\Server\Log\Events\SplitCompleted;
use App\Integration\Server\Log\LogLineParser;
use App\Integration\Server\Log\SessionType;

const CAR_A = '4e21b8d38d7f3000-32c9d9c8bf6327be';

function parseLine(string $line): ?object
{
    return (new LogLineParser)->parse($line);
}

it('parses Creating Car with a tab separator', function () {
    $event = parseLine('[2026-11-02 18:20:38.215] [server] [info] [C:5|G:0] [ServerVehicleSystem]['.CAR_A."] Creating Car (Driver 3\t76561198000000003)");

    expect($event)->toBeInstanceOf(CarCreated::class)
        ->carId->toBe(CAR_A)
        ->steamId->toBe('76561198000000003')
        ->name->toBe('Driver 3')
        ->and($event->at->format('Y-m-d H:i:s.v e'))->toBe('2026-11-02 18:20:38.215 UTC');
});

it('parses the connected line with the car runtime name', function () {
    $event = parseLine('[2026-11-02 18:20:38.457] [gameplay] [info] 76561198000000003 connected (true) on car ks_alfa_romeo_giulia_gtam, with new carId '.CAR_A);

    expect($event)->toBeInstanceOf(CarConnected::class)
        ->steamId->toBe('76561198000000003')
        ->runtimeName->toBe('ks_alfa_romeo_giulia_gtam')
        ->carId->toBe(CAR_A);
});

it('distinguishes a destroy by connection from a destroy by id', function () {
    $byConnection = parseLine('[2026-11-02 18:36:03.096] [server] [info] [C:5|G:0] [ServerVehicleSystem]['.CAR_A."] Destroying Car(1) By ConnectionID (Driver 3\t76561198000000003)");
    $byId = parseLine('[2026-11-02 18:20:38.214] [server] [info] [C:5|G:0] [ServerVehicleSystem]['.CAR_A."] Destroying Car(1) By ID (Driver 3\t76561198000000003)");

    expect($byConnection)->toBeInstanceOf(CarDestroyed::class)->byConnection->toBeTrue()->steamId->toBe('76561198000000003')
        ->and($byId)->toBeInstanceOf(CarDestroyed::class)->byConnection->toBeFalse();
});

it('parses both name-bearing connection lines', function () {
    $gamecar = parseLine('[2026-11-02 18:37:37.380] [server] [info] connecting gamecar '.CAR_A.' (Driver 3 | 76561198000000003)');
    $connecting = parseLine('[2026-11-02 18:20:38.457] [server] [info] Connecting Car '.CAR_A.' (Driver 3 | 76561198000000003 (AI: false)');

    expect($gamecar)->toBeInstanceOf(DriverNamed::class)->name->toBe('Driver 3')->steamId->toBe('76561198000000003')
        ->and($connecting)->toBeInstanceOf(DriverNamed::class)->name->toBe('Driver 3')->carId->toBe(CAR_A);
});

it('splits the name on the last pipe separator', function () {
    $event = parseLine('[2026-11-02 18:20:38.457] [server] [info] Connecting Car '.CAR_A.' (Ana | María | 76561198000000009 (AI: false)');

    expect($event)->toBeInstanceOf(DriverNamed::class)->name->toBe('Ana | María');
});

it('keeps accents and apostrophes and trims trailing spaces in names', function () {
    $event = parseLine('[2026-11-02 18:20:38.215] [server] [info] [C:5|G:0] [ServerVehicleSystem]['.CAR_A."] Creating Car (José O'Brien  \t76561198000000003)");

    expect($event)->toBeInstanceOf(CarCreated::class)->name->toBe("José O'Brien");
});

it('parses a completed lap into milliseconds and keeps the raw line', function () {
    $line = '[2026-11-02 16:59:42.293] [gameplay] [info] New lap carId '.CAR_A.': 01:49.530';
    $event = parseLine($line."\r\n");

    expect($event)->toBeInstanceOf(LapCompleted::class)
        ->carId->toBe(CAR_A)
        ->lapMs->toBe(109530)
        ->rawLine->toBe($line);
});

it('parses split and rejected lap lines', function () {
    $split = parseLine('[2026-11-02 17:15:01.830] [gameplay] [info] Split completed for car '.CAR_A.': (51468 ms, splitindex 2) lap:7');
    $rejected = parseLine("[2026-11-02 16:57:34.081] [gameplay] [error] Couldn't create lap from opensplits (carId ".CAR_A.'): Splitcollection 1/3, split times: 2, ');

    expect($split)->toBeInstanceOf(SplitCompleted::class)->lapNumber->toBe(7)->splitIndex->toBe(2)
        ->and($rejected)->toBeInstanceOf(LapRejected::class)->carId->toBe(CAR_A);
});

it('parses session, phase and player lines', function () {
    $practice = parseLine('[2026-10-20 16:13:05.882] [gameplay] [info] TimeAttackRemote Practice created');
    $qualifying = parseLine('[2026-11-02 16:56:40.968] [gameplay] [info] TimeAttackRemote Qualifying created');
    $race = parseLine('[2026-11-02 17:12:35.042] [gameplay] [info] InstantRaceRemote Race created');
    $phase = parseLine('[2026-11-02 17:28:14.490] [gameplay] [info] [SERVER] setSessionPhase Ended');
    $players = parseLine('[2026-11-02 18:20:38.037] [server] [info] Server updated: 2 players');

    expect($practice)->toBeInstanceOf(SessionCreated::class)->type->toBe(SessionType::Practice)
        ->and($qualifying->type)->toBe(SessionType::Qualifying)
        ->and($race->type)->toBe(SessionType::Race)
        ->and($phase)->toBeInstanceOf(SessionPhase::class)->phase->toBe('Ended')
        ->and($players)->toBeInstanceOf(PlayersUpdated::class)->count->toBe(2);
});

it('parses server restart markers with and without timestamp', function () {
    $fingerprint = parseLine("Runtime fingerprint: proton='unknown' app_id=4564210 install_dir=/data/server display=:99");
    $season = parseLine('[2026-10-20 16:13:05.786] [gameplay] [info] loading season Race Weekend');

    expect($fingerprint)->toBeInstanceOf(ServerRestarted::class)->at->toBeNull()
        ->and($season)->toBeInstanceOf(ServerRestarted::class)->at->not->toBeNull();
});

it('returns null for unknown and malformed lines', function (string $line) {
    expect(parseLine($line))->toBeNull();
})->with([
    'ping' => '[2026-10-20 16:11:47.106] [network] [info] Received Ping from 192.0.2.53 [56442]',
    'docker wrapper' => 'Stopping AssettoCorsaEVOServer.exe (pid=1003)',
    'free' => 'free(): corrupted unsorted chunks',
    'empty' => '',
    'garbage' => "\x00\xff not a log line",
    'bad timestamp' => '[2026-13-45 99:99:99.999] [gameplay] [info] TimeAttackRemote Practice created',
    'lap without time' => '[2026-11-02 16:59:42.293] [gameplay] [info] New lap carId '.CAR_A.': abc',
    'creating car without tab' => '[2026-11-02 18:20:38.215] [server] [info] [ServerVehicleSystem]['.CAR_A.'] Creating Car (Driver 3 76561198000000003)',
    'truncated' => '[2026-11-02 18:20:38.215] [server] [info',
]);
