<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;

class ProfileController extends Controller
{
    private const int COOKIE_MINUTES = 60 * 24 * 365;

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $locale = $request->validated('locale');

        $user->update([
            'name' => $request->validated('name'),
            'locale' => $locale,
            'show_in_rankings' => $request->boolean('show_in_rankings'),
        ]);

        app()->setLocale($locale);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.profile.saved')]);

        return redirect()->route('account.show')->withCookie(Cookie::make('locale', $locale, self::COOKIE_MINUTES));
    }
}
