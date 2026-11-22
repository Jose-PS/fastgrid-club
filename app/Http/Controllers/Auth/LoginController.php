<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginRedirect;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __invoke(Request $request, LoginRedirect $loginRedirect): Response
    {
        $redirect = $request->query('redirect');

        $loginRedirect->remember(is_string($redirect) ? $redirect : null);

        return Inertia::render('auth/login');
    }
}
