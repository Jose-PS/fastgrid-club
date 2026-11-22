<?php

declare(strict_types=1);

return [

    'timezone' => 'Europe/Madrid',

    'locales' => ['es', 'en'],

    'default_locale' => 'es',

    'brand' => [
        'name' => config('app.name'),
        'social_handle' => env('MVP_SOCIAL_HANDLE') ?: '@fastgrid_club',
        'social' => [
            'discord' => 'https://discord.gg/gnbgZeqxVx',
            'instagram' => 'https://www.instagram.com/fastgrid.club/',
            'x' => 'https://x.com/fastgrid_club',
            'bluesky' => 'https://bsky.app/profile/fastgrid.club',
        ],
    ],

    'voting' => [
        'closes_on' => 'sunday',
        'closes_at' => '23:59',
    ],

    'tracks' => [
        'path' => resource_path('tracks'),
        'slugs' => [
            'Brands Hatch|GP' => 'brands-hatch-gp',
            'Brands Hatch|Indy' => 'brands-hatch-indy',
            'Circuit Of The Americas|GP' => 'cota-gp',
            'Circuit Of The Americas|National' => 'cota-national',
            'Circuit de Spa Francorchamps|GP' => 'spa-gp',
            'Donington Park|GP' => 'donington-park-gp',
            'Donington Park|National' => 'donington-park-national',
            'Fuji Speedway|GP' => 'fuji-speedway-gp',
            'Fuji Speedway|GP Short' => 'fuji-speedway-gp-short',
            'Imola|GP' => 'imola-gp',
            'Kyalami|GP' => 'kyalami-gp',
            'Laguna Seca|GP' => 'laguna-seca-gp',
            'Monza|GP' => 'monza-gp',
            'Mount Panorama|GP' => 'mount-panorama-gp',
            'Nurburgring|24h' => 'nurburgring-24h',
            'Nurburgring|Gp Strecke' => 'nurburgring-gp-strecke',
            'Nurburgring|Nordschleife' => 'nurburgring-nordschleife',
            'Nurburgring|Sprint' => 'nurburgring-sprint',
            'Nurburgring|Touristenfahrten' => 'nurburgring-touristenfahrten',
            'Oulton Park|Fosters' => 'oulton-park-fosters',
            'Oulton Park|International' => 'oulton-park-international',
            'Paul Ricard|Layout 1A-V2' => 'paul-ricard-1a-v2',
            'Paul Ricard|Layout 1C-V2' => 'paul-ricard-1c-v2',
            'Paul Ricard|Layout 3A' => 'paul-ricard-3a',
            'Paul Ricard|Layout 3C' => 'paul-ricard-3c',
            'Red Bull Ring|GP' => 'red-bull-ring-gp',
            'Red Bull Ring|National' => 'red-bull-ring-national',
            'Road Atlanta|GP' => 'road-atlanta-gp',
            'Sebring International Raceway|GP' => 'sebring-gp',
            'Suzuka|East' => 'suzuka-east',
            'Suzuka|GP' => 'suzuka-gp',
            'Suzuka|West' => 'suzuka-west',
            'Watkins Glen International|GP' => 'watkins-glen-gp',
            'Watkins Glen International|GP Inner Loop' => 'watkins-glen-gp-inner-loop',
            'Watkins Glen International|Short' => 'watkins-glen-short',
            'Watkins Glen International|Short Inner Loop' => 'watkins-glen-short-inner-loop',
        ],
    ],

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'login_link' => [
        'ttl_minutes' => 15,
    ],

    'max_proposals_per_user' => 1,

    'proposals_cache_seconds' => 5,

    'status_cache_seconds' => 5,

    'donations' => [
        'cache_seconds' => 60,
        'domains' => [
            'ko_fi' => 'ko-fi.com',
            'patreon' => 'patreon.com',
            'paypal' => 'paypal.me',
        ],
    ],

    'server_snapshot' => [
        'stale_after_seconds' => 45,
    ],

    'vote_intent' => [
        'ttl_minutes' => 10,
    ],

    'recent_confirmation' => [
        'minutes' => 10,
    ],

    'rate_limits' => [
        'public' => ['per_minute' => 60],
        'login' => ['per_minute' => 10],
        'link' => ['per_minute' => 10],
        'magic_link' => ['per_hour_by_email' => 5, 'per_hour_by_ip' => 20],
        'magic_link_consume' => ['per_minute' => 10],
        'passkeys' => ['per_minute' => 10],
        'vote' => ['per_minute' => 30],
        'proposal' => ['per_hour' => 5],
        'status' => ['per_minute' => 60],
        'admin' => ['per_minute' => 30],
    ],

];
