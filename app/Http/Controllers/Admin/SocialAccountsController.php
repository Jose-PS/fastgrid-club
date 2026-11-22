<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\RecordAudit;
use App\Actions\Social\UpdateSocialAccount;
use App\Enums\SocialChannelName;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Integration\Social\SocialConnectionTester;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SocialAccountsController extends Controller
{
    public function update(UpdateSocialAccountRequest $request, SocialChannelName $channel, UpdateSocialAccount $update): RedirectResponse
    {
        $update->handle(
            $this->admin($request),
            $channel,
            $request->boolean('enabled'),
            $request->values(),
            $request->cleared(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.social.accounts.saved')]);

        return back();
    }

    public function test(Request $request, SocialChannelName $channel, SocialConnectionTester $tester, RecordAudit $recordAudit): RedirectResponse
    {
        $result = $tester->test($channel);

        $recordAudit->handle(
            $this->admin($request),
            'social_account.tested',
            SocialAccount::forChannel($channel),
            null,
            ['channel' => $channel->value, 'ok' => $result['ok']],
        );

        Inertia::flash('toast', ['type' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
