<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConsumeLoginLink;
use App\Actions\Auth\IssueLoginLink;
use App\Actions\Auth\LoginRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoginLinkRequest;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LoginLinkController extends Controller
{
    public function store(StoreLoginLinkRequest $request, IssueLoginLink $issueLoginLink): RedirectResponse
    {
        $issueLoginLink->handle($request->validated('email'), $request->ip(), app()->getLocale());

        Inertia::flash('linkSent', __('login.linkSent'));

        return back(fallback: route('login'));
    }

    public function show(string $token, ConsumeLoginLink $consumeLoginLink, Translations $translations): Response
    {
        return Inertia::render('auth/link', [
            'consumeUrl' => $consumeLoginLink->isUsable($token) ? route('login.link.consume', $token) : null,
            'pageTranslations' => $translations->forGroups(['link']),
        ]);
    }

    public function consume(string $token, ConsumeLoginLink $consumeLoginLink, LoginRedirect $loginRedirect): RedirectResponse
    {
        $user = $consumeLoginLink->handle($token);

        if ($user === null) {
            return redirect()->route('login.link.show', $token);
        }

        return $loginRedirect->completeLogin($user);
    }
}
