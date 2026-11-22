<?php

declare(strict_types=1);

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostType;
use App\Integration\Social\BlueskyChannel;
use App\Integration\Social\DiscordChannel;
use App\Integration\Social\InstagramChannel;
use App\Jobs\PublishSocialPost;
use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::disk('public')->put('social/1.png', 'png-bytes');
    config()->set('services.instagram.poll_sleep_ms', 0);
});

function approvedPost(array $attributes = []): SocialPost
{
    return SocialPost::factory()->status(SocialPostStatus::Approved)->create([
        'image_path' => 'social/1.png',
        'text' => 'Nueva semana #FastGrid https://fastgrid.club',
        'data' => ['circuit' => 'Monza'],
        ...$attributes,
    ]);
}

function configureChannels(): void
{
    SocialAccount::factory()->discord()->create();
    SocialAccount::factory()->bluesky()->create();
}

function fakeBluesky(): array
{
    return [
        'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'jwt', 'did' => 'did:plc:abc']),
        'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'cid']]]),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc/app.bsky.feed.post/3kabc']),
    ];
}

test('approving a post queues the publishing job', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $post = SocialPost::factory()->create();

    $this->actingAs($admin)->post(route('admin.social.approve', $post))->assertRedirect();

    Queue::assertPushed(PublishSocialPost::class, fn (PublishSocialPost $job): bool => $job->socialPostId === $post->id);
});

test('a rejected or already approved post queues nothing', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $post = SocialPost::factory()->status(SocialPostStatus::Approved)->create();

    $this->actingAs($admin)->post(route('admin.social.approve', $post))->assertSessionHasErrors('post');

    Queue::assertNothingPushed();
});

test('without credentials every channel is skipped and the post is published', function () {
    Http::fake();
    $post = approvedPost();

    (new PublishSocialPost($post->id))->handle(app(DiscordChannel::class), app(BlueskyChannel::class), app(InstagramChannel::class));

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Published)
        ->and($post->published_at)->not->toBeNull()
        ->and($post->deliveries['discord']['status'])->toBe('skipped')
        ->and($post->deliveries['bluesky']['status'])->toBe('skipped')
        ->and($post->deliveries['instagram']['status'])->toBe('skipped');
    Http::assertNothingSent();
});

test('it publishes on every configured channel and stores the deliveries', function () {
    SocialAccount::factory()->bluesky()->create();
    SocialAccount::factory()->discord()->create(['credentials' => [
        'webhook_announcements' => 'https://discord.com/api/webhooks/1/secret-token',
        'announcements_role_id' => '555',
    ]]);
    Http::fake(['discord.com/*' => Http::response(['id' => '999'])] + fakeBluesky());
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Published)
        ->and($post->deliveries['discord'])->toMatchArray(['status' => 'ok', 'id' => '999'])
        ->and($post->deliveries['bluesky'])->toMatchArray(['status' => 'ok', 'uri' => 'at://did:plc:abc/app.bsky.feed.post/3kabc']);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'discord.com')) {
            return false;
        }

        $payload = collect($request->data())->firstWhere('name', 'payload_json');

        return str_contains($request->url(), 'wait=true')
            && str_contains($payload['contents'], '<@&555>')
            && str_contains($payload['contents'], '"roles":["555"]')
            && ! str_contains($payload['contents'], 'everyone');
    });
});

test('a failing channel marks the post failed while the other stays ok and no secrets are stored', function () {
    configureChannels();
    Http::fake(['discord.com/*' => Http::response('boom', 500)] + fakeBluesky());
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Failed)
        ->and($post->published_at)->toBeNull()
        ->and($post->deliveries['discord']['status'])->toBe('failed')
        ->and($post->deliveries['bluesky']['status'])->toBe('ok')
        ->and(json_encode($post->deliveries))->not->toContain('secret-token')->not->toContain('app-secret');
});

test('a retry only publishes the channels that are not ok', function () {
    configureChannels();
    Http::fake(['discord.com/*' => Http::response(['id' => '1'])] + fakeBluesky());
    $post = approvedPost([
        'status' => SocialPostStatus::Failed,
        'deliveries' => [
            'discord' => ['status' => 'failed', 'error' => 'x', 'at' => 'now'],
            'bluesky' => ['status' => 'ok', 'uri' => 'at://did:plc:abc/app.bsky.feed.post/old', 'at' => 'now'],
        ],
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.social.retry', $post))->assertRedirect()->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Published)
        ->and($post->deliveries['discord']['status'])->toBe('ok')
        ->and($post->deliveries['bluesky']['uri'])->toEndWith('/old')
        ->and(AuditLog::query()->where('action', 'social_post.retried')->count())->toBe(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'bsky.social'));
});

test('only failed posts can be retried', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    foreach ([SocialPostStatus::Pending, SocialPostStatus::Approved, SocialPostStatus::Published, SocialPostStatus::Rejected] as $status) {
        $post = SocialPost::factory()->status($status)->create();

        $this->actingAs($admin)->post(route('admin.social.retry', $post))->assertSessionHasErrors('post');
    }

    Queue::assertNothingPushed();
});

test('the job never publishes posts that are not approved or failed', function (SocialPostStatus $status) {
    configureChannels();
    Http::fake();
    $post = approvedPost(['status' => $status]);

    dispatch_sync(new PublishSocialPost($post->id));

    expect($post->fresh()->status)->toBe($status)->and($post->fresh()->deliveries)->toBeNull();
    Http::assertNothingSent();
})->with([SocialPostStatus::Pending, SocialPostStatus::Rejected, SocialPostStatus::Published]);

test('a post without image is not published and waits', function () {
    configureChannels();
    Http::fake();
    $post = approvedPost(['image_path' => null]);

    $job = Mockery::mock(PublishSocialPost::class, [$post->id])->makePartial();
    $job->shouldReceive('release')->once()->with(PublishSocialPost::IMAGE_WAIT_SECONDS);
    $job->handle(app(DiscordChannel::class), app(BlueskyChannel::class), app(InstagramChannel::class));

    expect($post->fresh()->status)->toBe(SocialPostStatus::Approved)->and($post->fresh()->deliveries)->toBeNull();
    Http::assertNothingSent();
});

test('the bluesky payload has facets with utf8 byte offsets and the image embed', function () {
    $post = SocialPost::factory()->make([
        'type' => SocialPostType::WeekAnnouncement,
        'text' => 'Año nuevo 🏁 #FastGrid y #Monza2 en https://fastgrid.club/semana.',
        'data' => ['circuit' => 'Monza'],
    ]);

    $record = app(BlueskyChannel::class)->record($post, ['$type' => 'blob']);
    $facets = $record['facets'];

    $slice = fn (array $facet): string => substr($post->text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);

    expect($facets)->toHaveCount(3)
        ->and($slice($facets[0]))->toBe('#FastGrid')
        ->and($facets[0]['features'][0])->toBe(['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'FastGrid'])
        ->and($slice($facets[1]))->toBe('#Monza2')
        ->and($slice($facets[2]))->toBe('https://fastgrid.club/semana')
        ->and($facets[2]['features'][0])->toBe(['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://fastgrid.club/semana'])
        ->and($facets[0]['index']['byteStart'])->toBeGreaterThan(mb_strpos($post->text, '#FastGrid'))
        ->and($record['langs'])->toBe(['es', 'en'])
        ->and($record['embed']['images'][0]['alt'])->toContain('Monza')
        ->and($record['embed']['images'][0]['aspectRatio'])->toBe(['width' => 1080, 'height' => 1350]);
});

function realPng(): string
{
    $image = imagecreatetruecolor(8, 10);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function fakeInstagram(array $statuses = ['FINISHED']): array
{
    return [
        'graph.facebook.com/*/17841400000000000/media' => Http::response(['id' => 'container-1']),
        'graph.facebook.com/*/container-1*' => Http::sequence()
            ->push(['status_code' => $statuses[0]])
            ->push(['status_code' => $statuses[1] ?? $statuses[0]]),
        'graph.facebook.com/*/17841400000000000/media_publish' => Http::response(['id' => 'media-1']),
        'graph.facebook.com/*/media-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
    ];
}

test('it publishes on instagram using a jpeg copy of the image and stores id and permalink', function () {
    SocialAccount::factory()->instagram()->create();
    Storage::disk('public')->put('social/1.png', realPng());
    Http::fake(fakeInstagram(['IN_PROGRESS', 'FINISHED']));
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Published)
        ->and($post->deliveries['instagram'])->toMatchArray(['status' => 'ok', 'id' => 'media-1', 'permalink' => 'https://www.instagram.com/p/abc/']);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/me/'));
    Storage::disk('public')->assertExists('social/1.jpg');
    expect(substr((string) Storage::disk('public')->get('social/1.jpg'), 0, 3))->toBe("\xFF\xD8\xFF");
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/17841400000000000/media')
        && str_ends_with($request->url(), '/17841400000000000/media')
        && $request['access_token'] === 'ig-secret'
        && str_ends_with($request['image_url'], 'social/1.jpg')
        && $request['caption'] === $post->text);
});

test('a failing instagram container marks the post failed without leaking the token', function () {
    SocialAccount::factory()->instagram()->create();
    Storage::disk('public')->put('social/1.png', realPng());
    Http::fake(fakeInstagram(['ERROR']));
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Failed)
        ->and($post->deliveries['instagram']['status'])->toBe('failed')
        ->and(json_encode($post->deliveries))->not->toContain('ig-secret');
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/media_publish'));
});

test('an instagram api error does not leak the token', function () {
    SocialAccount::factory()->instagram()->create();
    Storage::disk('public')->put('social/1.png', realPng());
    Http::fake(['graph.facebook.com/*/17841400000000000/media' => Http::response(['error' => ['message' => 'Invalid image']], 400)]);
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    expect($post->fresh()->deliveries['instagram']['status'])->toBe('failed')
        ->and(json_encode($post->fresh()->deliveries))->not->toContain('ig-secret');
});

test('an instagram container that never finishes fails the delivery', function () {
    SocialAccount::factory()->instagram()->create();
    config()->set('services.instagram.poll_attempts', 2);
    Storage::disk('public')->put('social/1.png', realPng());
    Http::fake(fakeInstagram(['IN_PROGRESS']));
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    expect($post->fresh()->deliveries['instagram']['error'])->toContain('not ready in time');
});

test('a paused channel is skipped even with credentials while the others publish', function () {
    SocialAccount::factory()->discord()->paused()->create();
    SocialAccount::factory()->bluesky()->create();
    Http::fake(fakeBluesky());
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    $post->refresh();

    expect($post->status)->toBe(SocialPostStatus::Published)
        ->and($post->deliveries['discord']['status'])->toBe('skipped')
        ->and($post->deliveries['bluesky']['status'])->toBe('ok');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'discord.com'));
});

test('a channel without its required credentials is skipped', function () {
    SocialAccount::factory()->bluesky()->create(['credentials' => ['identifier' => 'fastgrid.bsky.social']]);
    SocialAccount::factory()->instagram()->create(['credentials' => ['access_token' => 'ig-secret']]);
    SocialAccount::factory()->discord()->create(['credentials' => ['webhook_results' => 'https://discord.com/api/webhooks/2/results-token']]);
    Http::fake();
    $post = approvedPost();

    dispatch_sync(new PublishSocialPost($post->id));

    $deliveries = $post->fresh()->deliveries;

    expect($deliveries['bluesky']['status'])->toBe('skipped')
        ->and($deliveries['instagram']['status'])->toBe('skipped')
        ->and($deliveries['discord']['status'])->toBe('skipped');
    Http::assertNothingSent();
});
