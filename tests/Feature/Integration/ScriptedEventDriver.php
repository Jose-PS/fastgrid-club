<?php

declare(strict_types=1);

use App\Integration\Server\Data\LogEvent;
use App\Integration\Server\Data\LogEvents;
use App\Integration\Server\FakeDriver;
use App\Integration\Server\ServerDriverException;

final class ScriptedEventDriver extends FakeDriver
{
    /** @var list<string> */
    public static array $lines = [];

    public static int $offset = 0;

    public static int $calls = 0;

    public static ?ServerDriverException $failure = null;

    public static function reset(): void
    {
        self::$lines = [];
        self::$offset = 0;
        self::$calls = 0;
        self::$failure = null;
    }

    public function logEvents(int $afterId, int $limit): LogEvents
    {
        self::$calls++;

        if (self::$failure !== null) {
            throw self::$failure;
        }

        $events = [];

        foreach (self::$lines as $index => $line) {
            $id = self::$offset + $index + 1;

            if ($id > $afterId) {
                $events[] = new LogEvent($id, $line);
            }

            if (count($events) >= $limit) {
                break;
            }
        }

        $firstId = self::$lines === [] ? null : self::$offset + 1;
        $lastId = self::$lines === [] ? null : self::$offset + count(self::$lines);

        return new LogEvents($events, $firstId, $lastId);
    }
}
