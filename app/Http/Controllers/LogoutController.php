<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route(PublicRoutes::name('home', PublicRoutes::localeFromReferer($request)));
    }
}
