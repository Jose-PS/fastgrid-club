<?php

declare(strict_types=1);

use App\Actions\Social\PrepareSocialPosts;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostType;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\SessionType;
use App\Jobs\RenderSocialPostImageJob;
use App\Mail\SocialPostReadyMail;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\RaceResult;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\SocialPost;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function socialNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');
}

function socialWeek(array $attributes = []): Week
{
    return Week::factory()->startingAt(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'))->create($attributes);
}

function socialRace(Server $server, string $startedAt, Track $track, array $winner = [], bool $withResult = true): ServerSession
{
    $session = ServerSession::factory()->create([
        'server_id' => $server->id,
        'type' => SessionType::Race,
        'started_at' => CarbonImmutable::parse($startedAt, 'UTC'),
        'ended_at' => CarbonImmutable::parse($startedAt, 'UTC')->addMinutes(20),
        'track_id' => $track->id,
    ]);

    if ($withResult) {
        RaceResult::factory()->create(array_merge([
            'server_session_id' => $session->id,
            'driver_id' => Driver::factory()->create(['last_name' => 'Rapido'])->id,
            'car_id' => Car::factory()->create(['display_name' => 'Ferrari 488'])->id,
            'position' => 1,
            'classified' => true,
            'total_ms' => 1_235_123,
        ], $winner));
    }

    return $session;
}

function socialLap(Week $week, Track $track, Driver $driver, Car $car, int $lapMs, array $attributes = []): Lap
{
    return Lap::factory()->create(array_merge([
        'server_id' => $week->server_id,
        'driver_id' => $driver->id,
        'car_id' => $car->id,
        'track_id' => $track->id,
        'lap_ms' => $lapMs,
        'is_valid' => true,
        'recorded_at' => $week->starts_at->addDays(2),
    ], $attributes));
}

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    config(['app.url' => 'https://fastgrid.club']);
});

test('a resolved week is announced once with circuit, cars and title and only admins are emailed', function () {
    $admins = User::factory()->admin()->count(2)->create();
    User::factory()->create();
    $track = Track::factory()->create(['display' => 'Monza GP']);
    $week = socialWeek([
        'status' => WeekStatus::Scheduled,
        'track_id' => $track->id,
        'resolved_at' => socialNow()->subHour(),
        'custom_title' => 'Copa de otoño',
    ]);
    $week->cars()->attach([
        Car::factory()->create(['display_name' => 'Ferrari 488'])->id,
        Car::factory()->create(['display_name' => 'BMW M4'])->id,
    ]);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(1);
    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);

    $post = SocialPost::query()->sole();

    expect($post->type)->toBe(SocialPostType::WeekAnnouncement)
        ->and($post->key)->toBe("week_announcement:{$week->id}")
        ->and($post->status)->toBe(SocialPostStatus::Pending)
        ->and($post->server_id)->toBe($week->server_id)
        ->and($post->week_id)->toBe($week->id)
        ->and($post->data['title'])->toBe('Copa de otoño')
        ->and($post->data['circuit'])->toBe('Monza GP')
        ->and($post->data['cars'])->toEqualCanonicalizing(['Ferrari 488', 'BMW M4'])
        ->and($post->text)->toContain('🏁 Nueva semana · New week: Monza GP', 'Copa de otoño', 'Coches · Cars: Ferrari 488, BMW M4', 'Vota y corre · Vote and race 👉 https://fastgrid.club', '#AssettoCorsa')
        ->and(mb_strlen($post->text))->toBeLessThanOrEqual(280);

    Queue::assertPushed(RenderSocialPostImageJob::class, 1);
    Queue::assertPushed(RenderSocialPostImageJob::class, fn (RenderSocialPostImageJob $job): bool => $job->post->is($post));
    Mail::assertQueued(SocialPostReadyMail::class, 2);
    foreach ($admins as $admin) {
        Mail::assertQueued(SocialPostReadyMail::class, fn (SocialPostReadyMail $mail): bool => $mail->hasTo($admin->email));
    }
});

test('weeks still voting, without circuit or resolved long ago are not announced', function () {
    $track = Track::factory()->create();
    socialWeek(['status' => WeekStatus::Voting, 'track_id' => $track->id, 'resolved_at' => socialNow()->subHour()]);
    Week::factory()->startingAt(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'))->create(['status' => WeekStatus::Scheduled, 'track_id' => null, 'resolved_at' => socialNow()->subHour()]);
    Week::factory()->startingAt(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'))->create(['status' => WeekStatus::Finished, 'track_id' => $track->id, 'resolved_at' => socialNow()->subDays(10)]);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);
    expect(SocialPost::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('a finished race day creates one post with every race in chronological order', function () {
    User::factory()->admin()->create();
    $server = Server::factory()->create();
    $track = Track::factory()->create(['display' => 'Imola GP']);

    socialRace($server, '2026-11-16 19:00:00', $track, ['total_ms' => 1_800_000, 'driver_id' => Driver::factory()->create(['last_name' => 'Tarde'])->id]);
    socialRace($server, '2026-11-16 17:00:00', $track, ['total_ms' => 1_235_123, 'driver_id' => Driver::factory()->create(['last_name' => 'Pronto'])->id]);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(1);
    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);

    $post = SocialPost::query()->sole();

    expect($post->type)->toBe(SocialPostType::DailyResults)
        ->and($post->key)->toBe("daily_results:{$server->id}:2026-11-16")
        ->and($post->data['date'])->toBe('2026-11-16')
        ->and($post->data['circuit'])->toBe('Imola GP')
        ->and(array_column($post->data['races'], 'winner'))->toBe(['Pronto', 'Tarde'])
        ->and(array_column($post->data['races'], 'started_at'))->toBe(['18:00', '20:00'])
        ->and($post->data['races'][0]['total_ms'])->toBe(1_235_123)
        ->and($post->text)->toContain('Resultados · Results 16/11: Imola GP', 'Ganadores · Winners: Pronto, Tarde')
        ->and(strpos($post->text, 'Pronto'))->toBeLessThan(strpos($post->text, 'Tarde'));

    Mail::assertQueued(SocialPostReadyMail::class, 1);
});

test('each day of races gets its own post and the day is the Madrid day', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create();

    socialRace($server, '2026-11-15 17:00:00', $track);
    socialRace($server, '2026-11-16 17:00:00', $track);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(2);
    expect(SocialPost::query()->pluck('key')->all())->toEqualCanonicalizing([
        "daily_results:{$server->id}:2026-11-15",
        "daily_results:{$server->id}:2026-11-16",
    ]);
});

test('races from today, from before the recent window or without a classified winner create nothing', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create();

    socialRace($server, '2026-11-16 23:30:00', $track);
    socialRace($server, '2026-11-17 08:00:00', $track);
    socialRace($server, '2026-11-14 18:00:00', $track);
    socialRace($server, '2026-11-16 17:00:00', $track, withResult: false);
    socialRace($server, '2026-11-15 17:00:00', $track, ['classified' => false]);
    socialRace($server, '2026-11-15 18:00:00', $track, ['position' => 2]);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);
    expect(SocialPost::query()->count())->toBe(0);
});

test('only race sessions count for the daily results', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create();
    $session = socialRace($server, '2026-11-16 17:00:00', $track);
    $session->update(['type' => SessionType::Qualifying]);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);
});

test('a finished week creates the top five best valid laps with paired circuits counted together', function () {
    User::factory()->admin()->create();
    $race = Track::factory()->create(['display' => 'Spa GP']);
    $practice = Track::factory()->practice()->create();
    $race->update(['paired_track_id' => $practice->id]);
    $week = socialWeek(['status' => WeekStatus::Finished, 'track_id' => $race->id, 'resolved_at' => socialNow()->subDays(8)]);
    $car = Car::factory()->create(['display_name' => 'Porsche 911']);
    $other = Car::factory()->create(['display_name' => 'Audi R8']);

    $a = Driver::factory()->create(['last_name' => 'Ana']);
    $b = Driver::factory()->create(['last_name' => 'Beto']);
    $c = Driver::factory()->create(['last_name' => 'Cris']);
    $d = Driver::factory()->create(['last_name' => 'Dani']);
    $e = Driver::factory()->create(['last_name' => 'Eli']);
    $f = Driver::factory()->create(['last_name' => 'Fran']);

    socialLap($week, $race, $a, $car, 140_000);
    socialLap($week, $practice, $a, $other, 139_000);
    socialLap($week, $practice, $b, $car, 139_500);
    socialLap($week, $race, $c, $car, 141_000);
    socialLap($week, $race, $d, $car, 142_000);
    socialLap($week, $race, $e, $car, 143_000);
    socialLap($week, $race, $f, $car, 144_000);
    socialLap($week, $race, $f, $car, 100_000, ['is_valid' => false]);
    socialLap($week, $race, $f, $car, 100_000, ['car_id' => null]);
    socialLap($week, $race, $f, $car, 100_000, ['recorded_at' => $week->starts_at->subHour()]);
    socialLap($week, $race, $f, $car, 100_000, ['recorded_at' => $week->ends_at->addHour()]);
    socialLap($week, $race, $f, $car, 100_000, ['server_id' => Server::factory()->create()->id]);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(1);
    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);

    $post = SocialPost::query()->sole();

    expect($post->type)->toBe(SocialPostType::WeeklyBestLaps)
        ->and($post->key)->toBe("weekly_best_laps:{$week->id}")
        ->and(array_column($post->data['rows'], 'driver'))->toBe(['Ana', 'Beto', 'Cris', 'Dani', 'Eli'])
        ->and($post->data['rows'][0]['lap_ms'])->toBe(139_000)
        ->and($post->data['rows'][0]['car'])->toBe('Audi R8')
        ->and($post->text)->toContain('Spa GP', '1. Ana 2:19.000 (Audi R8)', '5. Eli 2:23.000')
        ->and($post->text)->not->toContain('Fran')
        ->and(mb_strlen($post->text))->toBeLessThanOrEqual(280);
});

test('a finished week without valid laps or a week not finished yet creates nothing', function () {
    $track = Track::factory()->create();
    $empty = socialWeek(['status' => WeekStatus::Finished, 'track_id' => $track->id, 'resolved_at' => socialNow()->subDays(8)]);
    socialLap($empty, $track, Driver::factory()->create(), Car::factory()->create(), 100_000, ['is_valid' => false]);

    $active = Week::factory()->startingAt(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'))->create(['status' => WeekStatus::Active, 'track_id' => $track->id, 'resolved_at' => socialNow()->subDays(3)]);
    socialLap($active, $track, Driver::factory()->create(), Car::factory()->create(), 100_000);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);
});

test('a finished week that ended before the recent window is not turned into a post', function () {
    $track = Track::factory()->create();
    $week = Week::factory()->startingAt(CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'))->create(['status' => WeekStatus::Finished, 'track_id' => $track->id, 'resolved_at' => socialNow()->subDays(14)]);
    socialLap($week, $track, Driver::factory()->create(), Car::factory()->create(), 100_000);

    expect(app(PrepareSocialPosts::class)->handle(socialNow()))->toBe(0);
});

test('hidden drivers are shown with their game nickname', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create();
    $hiddenByUser = User::factory()->create(['show_in_rankings' => false]);

    socialRace($server, '2026-11-16 17:00:00', $track, [
        'driver_id' => Driver::factory()->create(['last_name' => 'Secreto', 'user_id' => $hiddenByUser->id])->id,
    ]);
    socialRace($server, '2026-11-16 19:00:00', $track, [
        'driver_id' => Driver::factory()->create(['last_name' => 'Oculto', 'hidden' => true])->id,
    ]);

    app(PrepareSocialPosts::class)->handle(socialNow());

    $post = SocialPost::query()->sole();

    expect($post->text)->toContain('Secreto', 'Oculto')->not->toContain('Piloto oculto')
        ->and(array_column($post->data['races'], 'winner'))->toBe(['Secreto', 'Oculto']);
});

test('the daily summary lists every winner once, counts repeats and shows a single car once', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create(['display' => 'Brands Hatch GP']);
    $names = ['Ana', 'Beto', 'Ana', 'Cris', 'Dani', 'Eli'];

    foreach ($names as $index => $name) {
        socialRace($server, '2026-11-16 '.(10 + $index).':00:00', $track, [
            'driver_id' => Driver::factory()->create(['last_name' => $name.$index % 3])->id,
            'car_id' => Car::factory()->create(['display_name' => 'Ferrari 488'])->id,
        ]);
    }

    app(PrepareSocialPosts::class)->handle(socialNow());

    $post = SocialPost::query()->sole();

    expect($post->data['races'])->toHaveCount(6)
        ->and($post->text)->toContain('Resultados · Results 16/11: Brands Hatch GP', 'Coche · Car: Ferrari 488', 'Ganadores · Winners: Ana0, Beto1, Ana2, Cris0, Dani1, Eli2')
        ->and(substr_count($post->text, 'Ferrari 488'))->toBe(1)
        ->and($post->text)->not->toContain(' · 1')
        ->and(mb_strlen($post->text))->toBeLessThanOrEqual(280);
});

test('a driver who wins several races appears once with a multiplier and unknown session track falls back to the week circuit', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create(['display' => 'Imola GP']);
    $week = socialWeek(['status' => WeekStatus::Active, 'track_id' => $track->id]);
    $driver = Driver::factory()->create(['last_name' => 'Rapido']);

    foreach (['17', '18'] as $hour) {
        $session = socialRace($server, "2026-11-16 {$hour}:00:00", $track, ['driver_id' => $driver->id]);
        $session->update(['track_id' => null, 'week_id' => $week->id]);
    }

    app(PrepareSocialPosts::class)->handle(socialNow());

    $post = SocialPost::query()->sole();

    expect($post->text)->toContain('Resultados · Results 16/11: Imola GP', 'Ganadores · Winners: Rapido ×2')
        ->and($post->data['circuit'])->toBe('Imola GP');
});

test('the weekly summary keeps the full top five within the limit', function () {
    $track = Track::factory()->create(['display' => 'Spa GP']);
    $week = socialWeek(['status' => WeekStatus::Finished, 'track_id' => $track->id, 'resolved_at' => socialNow()->subDays(8)]);

    foreach (['Alejandro', 'Beatriz', 'Carlos', 'Daniela', 'Eduardo'] as $index => $name) {
        socialLap($week, $track, Driver::factory()->create(['last_name' => $name]), Car::factory()->create(['display_name' => 'Modelo '.$index]), 139_000 + $index * 100);
    }

    app(PrepareSocialPosts::class)->handle(socialNow());

    $post = SocialPost::query()->sole();

    expect($post->text)->toContain('1. Alejandro 2:19.000', '5. Eduardo 2:19.400')
        ->and(mb_strlen($post->text))->toBeLessThanOrEqual(280);
});

test('the text never exceeds 280 characters even with many races or cars', function () {
    $server = Server::factory()->create();
    $track = Track::factory()->create();

    foreach (range(0, 7) as $hour) {
        socialRace($server, '2026-11-16 '.str_pad((string) (10 + $hour), 2, '0', STR_PAD_LEFT).':00:00', $track, [
            'driver_id' => Driver::factory()->create(['last_name' => 'PilotoConNombreLargo'.$hour])->id,
        ]);
    }

    $week = socialWeek(['status' => WeekStatus::Scheduled, 'track_id' => $track->id, 'resolved_at' => socialNow()->subHour(), 'custom_title' => str_repeat('Titulo ', 10)]);
    $week->cars()->attach(Car::factory()->count(10)->create(['display_name' => 'Coche con un nombre bastante largo'])->pluck('id'));

    app(PrepareSocialPosts::class)->handle(socialNow());

    expect(SocialPost::query()->count())->toBe(2);
    SocialPost::query()->each(function (SocialPost $post): void {
        expect(mb_strlen($post->text))->toBeLessThanOrEqual(280)->and($post->text)->toContain('#AssettoCorsa');
    });
});

test('the social:prepare command runs the action', function () {
    $this->artisan('social:prepare')->expectsOutput('Social posts created: 0.')->assertSuccessful();
});
