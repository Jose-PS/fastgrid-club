<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Alerts\CloseAlert;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AlertsController extends Controller
{
    public function close(Request $request, Alert $alert, CloseAlert $closeAlert): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $closeAlert->handle($alert, $user);

        return back();
    }
}
