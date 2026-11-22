<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialPostType: string
{
    case WeekAnnouncement = 'week_announcement';
    case DailyResults = 'daily_results';
    case WeeklyBestLaps = 'weekly_best_laps';
}
