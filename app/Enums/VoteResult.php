<?php

declare(strict_types=1);

namespace App\Enums;

enum VoteResult: string
{
    case Voted = 'voted';
    case Moved = 'moved';
    case Unchanged = 'unchanged';
    case Closed = 'closed';
    case Unavailable = 'unavailable';

    public function counted(): bool
    {
        return in_array($this, [self::Voted, self::Moved, self::Unchanged], true);
    }
}
