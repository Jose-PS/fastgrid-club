<?php

declare(strict_types=1);

use App\Enums\SocialPostStatus;
use App\Mail\SocialPostReadyMail;
use App\Models\AuditLog;
use App\Models\SocialPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->post = SocialPost::factory()->create(['text' => 'Texto original']);
});

test('guests and non admins get not found on every social route', function () {
    $routes = [
        ['get', route('admin.social.index')],
        ['patch', route('admin.social.update', $this->post)],
        ['post', route('admin.social.approve', $this->post)],
        ['post', route('admin.social.reject', $this->post)],
    ];

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url, ['text' => 'Cambiado'])->assertNotFound();
    }

    $this->actingAs(User::factory()->create());

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url, ['text' => 'Cambiado'])->assertNotFound();
    }

    expect($this->post->fresh()->text)->toBe('Texto original')
        ->and($this->post->fresh()->status)->toBe(SocialPostStatus::Pending);
});

test('the listing groups posts by status', function () {
    SocialPost::factory()->status(SocialPostStatus::Approved)->create();
    SocialPost::factory()->status(SocialPostStatus::Published)->create();
    SocialPost::factory()->status(SocialPostStatus::Failed)->create();
    SocialPost::factory()->status(SocialPostStatus::Rejected)->create();

    $this->actingAs($this->admin)->get(route('admin.social.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/social')
            ->where('maxLength', 280)
            ->has('pending', 1)
            ->has('approved', 3)
            ->has('rejected', 1));
});

test('an admin edits the text of a pending post with audit', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.social.update', $this->post), ['text' => ' Texto nuevo '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $audit = AuditLog::query()->where('action', 'social_post.text_updated')->sole();

    expect($this->post->fresh()->text)->toBe('Texto nuevo')
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->before)->toBe(['text' => 'Texto original'])
        ->and($audit->after)->toBe(['text' => 'Texto nuevo']);
});

test('the text is required and at most 280 characters', function (mixed $text) {
    $this->actingAs($this->admin)
        ->patch(route('admin.social.update', $this->post), ['text' => $text])
        ->assertSessionHasErrors('text');

    expect($this->post->fresh()->text)->toBe('Texto original');
})->with([null, '', '   ', str_repeat('a', 281)]);

test('a text of exactly 280 characters is accepted', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.social.update', $this->post), ['text' => str_repeat('ñ', 280)])
        ->assertSessionHasNoErrors();

    expect(mb_strlen($this->post->fresh()->text))->toBe(280);
});

test('approving stores who and when and rejecting leaves no approval', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00'));
    $other = SocialPost::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.social.approve', $this->post))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->post(route('admin.social.reject', $other))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $approved = $this->post->fresh();
    $rejected = $other->fresh();

    expect($approved->status)->toBe(SocialPostStatus::Approved)
        ->and($approved->approved_by)->toBe($this->admin->id)
        ->and($approved->approved_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($rejected->status)->toBe(SocialPostStatus::Rejected)
        ->and($rejected->approved_by)->toBeNull()
        ->and($rejected->approved_at)->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['social_post.approved', 'social_post.rejected'])->count())->toBe(2);
});

test('only pending posts can be edited approved or rejected', function (SocialPostStatus $status) {
    $post = SocialPost::factory()->status($status)->create(['text' => 'Intacto']);

    $this->actingAs($this->admin);
    $this->patch(route('admin.social.update', $post), ['text' => 'Cambiado'])->assertSessionHasErrors('post');
    $this->post(route('admin.social.approve', $post))->assertSessionHasErrors('post');
    $this->post(route('admin.social.reject', $post))->assertSessionHasErrors('post');

    $post->refresh();

    expect($post->status)->toBe($status)
        ->and($post->text)->toBe('Intacto')
        ->and($post->approved_by)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
})->with([SocialPostStatus::Approved, SocialPostStatus::Published, SocialPostStatus::Rejected, SocialPostStatus::Failed]);

test('the ready email links to the concrete post in the pending tab', function () {
    $html = (new SocialPostReadyMail($this->post))->render();

    expect($html)->toContain(route('admin.social.index', ['tab' => 'pendientes']).'#post-'.$this->post->id);
});
