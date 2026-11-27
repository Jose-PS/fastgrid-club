<?php

declare(strict_types=1);

use App\Integration\Server\Log\Data\AttributedLap;
use App\Integration\Server\Log\Data\LogProcessingResult;
use App\Integration\Server\Log\LogProcessor;
use App\Integration\Server\Log\SessionType;

function fixtureLines(string $name): array
{
    return file(__DIR__.'/../Fixtures/logs/'.$name, FILE_IGNORE_NEW_LINES);
}

function processFixture(string $name): LogProcessingResult
{
    return (new LogProcessor)->process(fixtureLines($name));
}

function lapsBySteamId(LogProcessingResult $result): array
{
    $counts = [];

    foreach ($result->laps as $lap) {
        $counts[$lap->steamId] = ($counts[$lap->steamId] ?? 0) + 1;
    }

    ksort($counts);

    return $counts;
}

it('attributes practice laps to each driver with their car', function () {
    $result = processFixture('practice-session.log');

    expect(lapsBySteamId($result))->toBe(['76561198000000001' => 15, '76561198000000002' => 5])
        ->and($result->metrics->laps)->toBe(20)
        ->and($result->metrics->lapsRejected)->toBe(2)
        ->and($result->metrics->lapsDiscarded)->toBe(0)
        ->and($result->metrics->lines)->toBe(416)
        ->and($result->sessions)->toBe([])
        ->and(collect($result->laps)->every(fn (AttributedLap $lap) => $lap->isValid && $lap->carRuntimeName === 'ks_bmw_m3_e46_csl' && $lap->sessionKey === null && $lap->lapNumber === null))->toBeTrue()
        ->and(collect($result->drivers)->pluck('name', 'steamId')->all())->toBe(['76561198000000001' => 'Driver 1', '76561198000000002' => 'Driver 2']);
});

it('assigns race weekend laps to the qualifying and race sessions', function () {
    $result = processFixture('race-weekend.log');

    [$qualifying, $race] = $result->sessions;

    expect($result->sessions)->toHaveCount(2)
        ->and($qualifying->type)->toBe(SessionType::Qualifying)
        ->and($qualifying->startedAt->format('H:i:s.v'))->toBe('16:56:40.968')
        ->and($qualifying->endedAt->format('H:i:s.v'))->toBe('17:12:35.042')
        ->and($race->type)->toBe(SessionType::Race)
        ->and($race->startedAt->format('H:i:s.v'))->toBe('17:12:35.042')
        ->and($race->endedAt->format('H:i:s.v'))->toBe('17:28:14.490')
        ->and($result->metrics->laps)->toBe(27)
        ->and($result->metrics->lapsRejected)->toBe(2)
        ->and($result->metrics->playersCount)->toBe(2);

    $inQualifying = collect($result->laps)->where('sessionKey', $qualifying->sourceLineHash);
    $inRace = collect($result->laps)->where('sessionKey', $race->sourceLineHash);

    expect($inQualifying)->toHaveCount(9)
        ->and($inRace)->toHaveCount(18)
        ->and($inRace->groupBy('steamId')->map->count()->all())->toBe(['76561198000000001' => 9, '76561198000000003' => 9])
        ->and($inRace->pluck('lapNumber')->unique()->sort()->values()->all())->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8])
        ->and($inQualifying->pluck('lapNumber')->filter()->all())->toBe([]);
});

it('keeps the steam id of a driver who reconnects with a new car id', function () {
    $result = processFixture('reconnect.log');

    expect(lapsBySteamId($result))->toBe(['76561198000000003' => 12, '76561198000000004' => 10])
        ->and($result->metrics->laps)->toBe(22)
        ->and($result->metrics->lapsRejected)->toBe(2)
        ->and($result->metrics->lapsDiscarded)->toBe(0)
        ->and(collect($result->drivers)->pluck('steamId')->sort()->values()->all())->toBe(['76561198000000003', '76561198000000004', '76561198000000005'])
        ->and(collect($result->sessions)->map(fn ($s) => $s->type)->all())->toBe([SessionType::Race, SessionType::Qualifying])
        ->and($result->sessions[1]->endedAt)->toBeNull();

    $afterReconnect = collect($result->laps)->where('steamId', '76561198000000003')->where('recordedAt', '>', $result->sessions[1]->startedAt);

    expect($afterReconnect)->toHaveCount(4)
        ->and($afterReconnect->pluck('carRuntimeName')->unique()->all())->toBe(['ks_chevrolet_camaro_zl1']);
});

it('discards laps that arrive for a car id destroyed by connection', function () {
    $result = processFixture('synthetic/stale-car.log');

    expect($result->laps)->toHaveCount(1)
        ->and($result->laps[0]->lapMs)->toBe(90000)
        ->and($result->laps[0]->driverName)->toBe("Ana María O'Brien")
        ->and($result->laps[0]->carRuntimeName)->toBe('ks_test_car')
        ->and($result->metrics->lapsDiscarded)->toBe(1);
});

it('does not invalidate the car map on a destroy by id', function () {
    $lines = [
        '[2026-11-03 10:00:00.000] [gameplay] [info] InstantRaceRemote Race created',
        "[2026-11-03 10:00:01.000] [server] [info] [C:1|G:0] [ServerVehicleSystem][aaaa000000000001-bbbb000000000001] Creating Car (Ana\t76561198000000009)",
        '[2026-11-03 10:00:02.000] [gameplay] [info] 76561198000000009 connected (true) on car ks_test_car, with new carId aaaa000000000001-bbbb000000000001',
        "[2026-11-03 10:05:00.000] [server] [info] [C:1|G:0] [ServerVehicleSystem][aaaa000000000001-bbbb000000000001] Destroying Car(0) By ID (Ana\t76561198000000009)",
        '[2026-11-03 10:05:01.000] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:30.000',
    ];

    $result = (new LogProcessor)->process($lines);

    expect($result->laps)->toHaveCount(1)->and($result->metrics->lapsDiscarded)->toBe(0)
        ->and($result->laps[0]->carRuntimeName)->toBe('ks_test_car');
});

it('discards laps for unmapped car ids', function () {
    $result = (new LogProcessor)->process(['[2026-11-03 10:05:01.000] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:30.000']);

    expect($result->laps)->toBe([])->and($result->metrics->lapsDiscarded)->toBe(1);
});

it('fills a missing name from the alternative connection line', function () {
    $lines = [
        '[2026-11-03 10:00:02.000] [gameplay] [info] 76561198000000009 connected (true) on car ks_test_car, with new carId aaaa000000000001-bbbb000000000001',
        '[2026-11-03 10:00:03.000] [server] [info] Connecting Car aaaa000000000001-bbbb000000000001 (Ana | Pérez | 76561198000000009 (AI: false)',
        '[2026-11-03 10:05:01.000] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:30.000',
    ];

    $result = (new LogProcessor)->process($lines);

    expect($result->laps[0]->driverName)->toBe('Ana | Pérez')->and($result->drivers[0]->name)->toBe('Ana | Pérez');
});

it('empties the map and closes the open session on a server restart', function () {
    $result = processFixture('server-restart.log');

    expect($result->metrics->restarts)->toBe(1)
        ->and($result->metrics->laps)->toBe(0)
        ->and($result->sessions)->toHaveCount(1)
        ->and($result->sessions[0]->type)->toBe(SessionType::Practice)
        ->and($result->sessions[0]->endedAt)->toBeNull();

    $processor = new LogProcessor;
    $lines = fixtureLines('practice-session.log');
    $processor->process($lines);
    $after = $processor->process([
        'Runtime fingerprint: proton=\'unknown\' app_id=4564210',
        '[2026-10-20 18:00:00.000] [gameplay] [info] New lap carId 4e00f01244ffc0ac-f34c0bac05cf749a: 01:18.495',
    ]);

    expect($after->laps)->toBe([])->and($after->metrics->lapsDiscarded)->toBe(1)->and($after->metrics->restarts)->toBe(1)
        ->and($processor->exportState()['cars'])->toBe([]);
});

it('closes an open session with the last timestamp when the restart line has none', function () {
    $processor = new LogProcessor;
    $processor->process([
        '[2026-11-03 10:00:00.000] [gameplay] [info] TimeAttackRemote Practice created',
        '[2026-11-03 10:20:00.000] [network] [info] Received Ping from 192.0.2.5 [1]',
        '[2026-11-03 10:30:00.000] [server] [info] Server updated: 1 players',
    ]);

    $result = $processor->process(['Runtime fingerprint: proton=\'unknown\'']);

    expect($result->sessions)->toHaveCount(1)
        ->and($result->sessions[0]->endedAt->format('H:i:s.v'))->toBe('10:30:00.000')
        ->and($processor->exportState()['session'])->toBeNull();
});

it('gives the same result splitting a fixture in two with exported state', function (string $fixture) {
    $lines = fixtureLines($fixture);
    $whole = (new LogProcessor)->process($lines);

    foreach ([0.25, 0.5, 0.8] as $ratio) {
        $cut = (int) (count($lines) * $ratio);

        $first = new LogProcessor;
        $a = $first->process(array_slice($lines, 0, $cut));
        $state = json_decode(json_encode($first->exportState(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $second = new LogProcessor;
        $second->importState($state);
        $b = $second->process(array_slice($lines, $cut));

        expect(array_merge($a->laps, $b->laps))->toEqual($whole->laps);

        $sessions = [];
        foreach ([...$a->sessions, ...$b->sessions] as $session) {
            $sessions[$session->sourceLineHash] = $session;
        }
        expect(array_values($sessions))->toEqual($whole->sessions);

        expect($a->metrics->laps + $b->metrics->laps)->toBe($whole->metrics->laps)
            ->and($a->metrics->lapsRejected + $b->metrics->lapsRejected)->toBe($whole->metrics->lapsRejected)
            ->and($a->metrics->lapsDiscarded + $b->metrics->lapsDiscarded)->toBe($whole->metrics->lapsDiscarded)
            ->and($a->metrics->restarts + $b->metrics->restarts)->toBe($whole->metrics->restarts)
            ->and($second->exportState())->toEqual((function () use ($lines) {
                $processor = new LogProcessor;
                $processor->process($lines);

                return $processor->exportState();
            })());
    }
})->with(['practice-session.log', 'race-weekend.log', 'reconnect.log', 'server-restart.log']);

it('produces a stable source hash per lap line', function () {
    $first = processFixture('race-weekend.log');
    $second = processFixture('race-weekend.log');

    $hashes = collect($first->laps)->pluck('sourceHash');

    expect($hashes->all())->toBe(collect($second->laps)->pluck('sourceHash')->all())
        ->and($hashes->unique())->toHaveCount(27)
        ->and($first->laps[0]->sourceHash)->toBe(hash('sha256', '[2026-11-02 16:59:42.293] [gameplay] [info] New lap carId 44a6bb478b2433ea-8c4c4fdd204c7692: 01:49.530'));
});

it('invalidates a lap that started before the car was teleported to the pits', function () {
    $result = (new LogProcessor)->process([
        "[2026-11-26 22:39:00.000] [server] [info] [C:1|G:0] [ServerVehicleSystem][aaaa000000000001-bbbb000000000001] Creating Car (Ana\t76561198000000009)",
        '[2026-11-26 22:39:45.419] [server] [info] Teleporting aaaa000000000001-bbbb000000000001 pos 0',
        '[2026-11-26 22:39:51.285] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:17.727',
    ]);

    expect($result->laps)->toHaveCount(1)->and($result->laps[0]->isValid)->toBeFalse();
});

it('keeps a lap valid when it started after the teleport', function () {
    $result = (new LogProcessor)->process([
        "[2026-11-26 23:11:00.000] [server] [info] [C:1|G:0] [ServerVehicleSystem][aaaa000000000001-bbbb000000000001] Creating Car (Ana\t76561198000000009)",
        '[2026-11-26 23:11:30.899] [server] [info] Teleporting aaaa000000000001-bbbb000000000001 pos 1 (StartingPositionType_Pit) [-137.35887 141.89728 -368.22183]',
        '[2026-11-26 23:13:22.372] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:41.799',
    ]);

    expect($result->laps)->toHaveCount(1)->and($result->laps[0]->isValid)->toBeTrue();
});

it('keeps the teleport mark when the processor state is split in two', function () {
    $processor = new LogProcessor;
    $processor->process([
        "[2026-11-26 22:39:00.000] [server] [info] [C:1|G:0] [ServerVehicleSystem][aaaa000000000001-bbbb000000000001] Creating Car (Ana\t76561198000000009)",
        '[2026-11-26 22:39:45.419] [server] [info] Teleporting aaaa000000000001-bbbb000000000001 pos 0',
    ]);

    $resumed = new LogProcessor;
    $resumed->importState(json_decode(json_encode($processor->exportState(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    $result = $resumed->process(['[2026-11-26 22:39:51.285] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:17.727']);

    expect($result->laps[0]->isValid)->toBeFalse()
        ->and($resumed->exportState()['teleports'])->toBe([]);
});

it('reads the lap number from the last split of the car', function () {
    $lines = [
        '[2026-11-03 10:00:01.000] [server] [info] [C:1|G:0] [ServerVehicleSystem][aaaa000000000001-bbbb000000000001] Creating Car (Ana'."\t".'76561198000000009)',
        '[2026-11-03 10:00:30.000] [gameplay] [info] Split completed for car aaaa000000000001-bbbb000000000001: (30000 ms, splitindex 0) lap:3',
        '[2026-11-03 10:01:30.000] [gameplay] [info] Split completed for car aaaa000000000001-bbbb000000000001: (30000 ms, splitindex 2) lap:3',
        '[2026-11-03 10:01:30.001] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:30.000',
        '[2026-11-03 10:03:00.000] [gameplay] [info] New lap carId aaaa000000000001-bbbb000000000001: 01:30.000',
    ];

    $result = (new LogProcessor)->process($lines);

    expect(collect($result->laps)->pluck('lapNumber')->all())->toBe([3, null]);
});
