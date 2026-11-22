<?php

declare(strict_types=1);

use App\Actions\Social\RenderSocialPostImage;
use App\Enums\SocialPostType;
use App\Jobs\RenderSocialPostImageJob;
use App\Models\SocialPost;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function fakeRenderer(string $png = 'png-bytes'): void
{
    Process::fake(function (PendingProcess $process) use ($png): ProcessResult {
        file_put_contents($process->command[2], $png);

        return Process::result();
    });
}

function failingRenderer(): void
{
    Process::fake(fn (): ProcessResult => Process::result(errorOutput: 'boom', exitCode: 1));
}

beforeEach(function () {
    Storage::fake('public');
    config(['app.url' => 'https://fastgrid.club']);
    $this->post = SocialPost::factory()->create([
        'type' => SocialPostType::WeeklyBestLaps,
        'data' => ['circuit' => 'Monza GP', 'rows' => []],
    ]);
});

test('a successful render stores the png and saves its path', function () {
    fakeRenderer();

    expect(app(RenderSocialPostImage::class)->handle($this->post))->toBeTrue();

    $path = $this->post->fresh()->image_path;

    expect($path)->toMatch("/^social\\/{$this->post->id}-[a-f0-9]{12}\\.png$/");
    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->get($path))->toBe('png-bytes');

    Process::assertRan(fn (PendingProcess $process): bool => $process->input !== null
        && json_decode($process->input, true)['type'] === 'weekly_best_laps'
        && json_decode($process->input, true)['url'] === 'https://fastgrid.club');
});

test('a failing renderer leaves the post without image and stores nothing', function () {
    failingRenderer();

    expect(app(RenderSocialPostImage::class)->handle($this->post))->toBeFalse()
        ->and($this->post->fresh()->image_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('an empty renderer output is a failure', function () {
    fakeRenderer('');

    expect(app(RenderSocialPostImage::class)->handle($this->post))->toBeFalse()
        ->and($this->post->fresh()->image_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a failed re-render keeps the previous image', function () {
    fakeRenderer('first');
    app(RenderSocialPostImage::class)->handle($this->post);
    $previous = $this->post->fresh()->image_path;

    failingRenderer();

    expect(app(RenderSocialPostImage::class)->handle($this->post->fresh()))->toBeFalse()
        ->and($this->post->fresh()->image_path)->toBe($previous);
    Storage::disk('public')->assertExists($previous);
});

test('a successful re-render replaces the previous file', function () {
    fakeRenderer('first');
    app(RenderSocialPostImage::class)->handle($this->post);
    $previous = $this->post->fresh()->image_path;
    $previousJpeg = substr($previous, 0, -4).'.jpg';
    Storage::disk('public')->put($previousJpeg, 'jpeg');

    fakeRenderer('second');
    app(RenderSocialPostImage::class)->handle($this->post->fresh());
    $current = $this->post->fresh()->image_path;

    expect($current)->not->toBe($previous);
    Storage::disk('public')->assertExists($current);
    Storage::disk('public')->assertMissing($previous);
    Storage::disk('public')->assertMissing($previousJpeg);
});

test('the job renders the image, skips posts that already have one and throws when rendering fails', function () {
    fakeRenderer();
    (new RenderSocialPostImageJob($this->post))->handle(app(RenderSocialPostImage::class));
    $path = $this->post->fresh()->image_path;

    expect($path)->not->toBeNull();

    failingRenderer();
    (new RenderSocialPostImageJob($this->post->fresh()))->handle(app(RenderSocialPostImage::class));
    expect($this->post->fresh()->image_path)->toBe($path);

    $pending = SocialPost::factory()->create();

    expect(fn () => (new RenderSocialPostImageJob($pending))->handle(app(RenderSocialPostImage::class)))
        ->toThrow(RuntimeException::class)
        ->and($pending->fresh()->image_path)->toBeNull();
});
