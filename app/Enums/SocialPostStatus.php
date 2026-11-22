<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialPostStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
