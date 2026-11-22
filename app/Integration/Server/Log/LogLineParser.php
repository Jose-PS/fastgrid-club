<?php

declare(strict_types=1);

namespace App\Integration\Server\Log;

use App\Integration\Server\Log\Events\CarConnected;
use App\Integration\Server\Log\Events\CarCreated;
use App\Integration\Server\Log\Events\CarDestroyed;
use App\Integration\Server\Log\Events\DriverNamed;
use App\Integration\Server\Log\Events\LapCompleted;
use App\Integration\Server\Log\Events\LapRejected;
use App\Integration\Server\Log\Events\LogEvent;
use App\Integration\Server\Log\Events\PlayersUpdated;
use App\Integration\Server\Log\Events\ServerRestarted;
use App\Integration\Server\Log\Events\SessionCreated;
use App\Integration\Server\Log\Events\SessionPhase;
use App\Integration\Server\Log\Events\SplitCompleted;
use Carbon\CarbonImmutable;

final class LogLineParser
{
    private const CAR_ID = '[0-9a-f]+-[0-9a-f]+';

    private const STEAM_ID = '\d{17}';

    public function parse(string $line): ?LogEvent
    {
        $line = rtrim($line, "\r\n");

        if (str_starts_with($line, 'Runtime fingerprint:')) {
            return new ServerRestarted(null);
        }

        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\] \[[^\]]*\] \[[^\]]*\] (.*)$/s', $line, $frame)) {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i:s.v', $frame[1], 'UTC');

        if ($at === null || $at->format('Y-m-d H:i:s.v') !== $frame[1]) {
            return null;
        }

        return $this->parseMessage($frame[2], $at, $line);
    }

    private function parseMessage(string $message, CarbonImmutable $at, string $line): ?LogEvent
    {
        $carId = self::CAR_ID;
        $steamId = self::STEAM_ID;

        if (preg_match("/^New lap carId ({$carId}): (\d{1,3}):(\d{2})\.(\d{3})$/", $message, $m)) {
            $lapMs = ((int) $m[2] * 60 + (int) $m[3]) * 1000 + (int) $m[4];

            return new LapCompleted($m[1], $lapMs, $at, $line);
        }

        if (preg_match("/^Split completed for car ({$carId}): \(\d+ ms, splitindex (\d+)\) lap:(\d+)$/", $message, $m)) {
            return new SplitCompleted($m[1], (int) $m[3], (int) $m[2], $at);
        }

        if (preg_match("/^Couldn't create lap from opensplits \(carId ({$carId})\)/", $message, $m)) {
            return new LapRejected($m[1], $at);
        }

        if (preg_match("/^(?:\[C:\d+\|G:\d+\] )?\[ServerVehicleSystem\]\[({$carId})\] Creating Car \((.*)\t({$steamId})\)$/s", $message, $m)) {
            return new CarCreated($m[1], $m[3], trim($m[2]), $at);
        }

        if (preg_match("/^(?:\[C:\d+\|G:\d+\] )?\[ServerVehicleSystem\]\[({$carId})\] Destroying Car\(\d+\) By (ConnectionID|ID) \((.*)\t({$steamId})\)$/s", $message, $m)) {
            return new CarDestroyed($m[1], $m[4], $m[2] === 'ConnectionID', $at);
        }

        if (preg_match("/^({$steamId}) connected \(true\) on car (\S+), with new carId ({$carId})$/", $message, $m)) {
            return new CarConnected($m[1], $m[2], $m[3], $at);
        }

        if (preg_match("/^connecting gamecar ({$carId}) \((.*) \| ({$steamId})\)$/s", $message, $m)) {
            return new DriverNamed($m[1], $m[3], trim($m[2]), $at);
        }

        if (preg_match("/^Connecting Car ({$carId}) \((.*) \| ({$steamId}) \(AI: false\)$/s", $message, $m)) {
            return new DriverNamed($m[1], $m[3], trim($m[2]), $at);
        }

        if (preg_match('/^(TimeAttackRemote Practice|TimeAttackRemote Qualifying|InstantRaceRemote Race) created$/', $message, $m)) {
            return new SessionCreated(match ($m[1]) {
                'TimeAttackRemote Practice' => SessionType::Practice,
                'TimeAttackRemote Qualifying' => SessionType::Qualifying,
                default => SessionType::Race,
            }, $at, $line);
        }

        if (preg_match('/^\[SERVER\] setSessionPhase (\w+)$/', $message, $m)) {
            return new SessionPhase($m[1], $at);
        }

        if (preg_match('/^Server updated: (\d+) players$/', $message, $m)) {
            return new PlayersUpdated((int) $m[1], $at);
        }

        if (str_starts_with($message, 'loading season')) {
            return new ServerRestarted($at);
        }

        return null;
    }
}
