<?php

declare(strict_types=1);

namespace App\Enums;

enum WeekStatus: string
{
    case Voting = 'voting';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Finished = 'finished';
}
