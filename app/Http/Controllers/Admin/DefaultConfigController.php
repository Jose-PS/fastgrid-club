<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\DefaultConfig\DeleteDefaultConfig;
use App\Actions\DefaultConfig\SaveDefaultConfig;
use App\Actions\Proposals\ProposalListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDefaultConfigRequest;
use App\Models\Server;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DefaultConfigController extends Controller
{
    public function show(Translations $translations, ProposalListing $listing): Response
    {
        $server = $this->activeServer();
        $config = $server->defaultConfig()->with('cars:id')->first();

        return Inertia::render('admin/default-config', [
            'pageTranslations' => $translations->forGroups(['admin', 'props']),
            'options' => $listing->options(),
            'config' => $config === null ? null : [
                'track_id' => $config->track_id,
                'car_ids' => $config->cars->pluck('id')->sort()->values()->all(),
                'custom_title' => $config->custom_title,
            ],
            'server' => ['name' => $server->name],
        ]);
    }

    public function update(UpdateDefaultConfigRequest $request, SaveDefaultConfig $saveDefaultConfig): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $saveDefaultConfig->handle(
            $user,
            $this->activeServer(),
            $request->integer('track_id'),
            $request->carIds(),
            $request->customTitle(),
        );

        return back();
    }

    public function destroy(Request $request, DeleteDefaultConfig $deleteDefaultConfig): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $deleteDefaultConfig->handle($user, $this->activeServer());

        return back();
    }

    private function activeServer(): Server
    {
        return Server::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
