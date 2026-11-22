<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconcileResult: string
{
    case Paused = 'paused';
    case Busy = 'busy';
    case NoConfig = 'no_config';
    case UpToDate = 'up_to_date';
    case Waiting = 'waiting';
    case Applied = 'applied';
    case Failed = 'failed';
    case Started = 'started';
}
