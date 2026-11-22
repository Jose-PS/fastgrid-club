<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Servers\ReapplyServerConfig;
use App\Actions\Servers\SetPlanningPaused;
use App\Enums\ConfigApplicationStatus;
use App\Enums\ReconcileResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePlanningPausedRequest;
use App\Models\ConfigApplication;
use App\Models\IntegrationEvent;
use App\Models\Server;
use App\Models\User;
use App\Support\AdminServerStatus;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class ServersController extends Controller
{
    private const int PER_PAGE = 10;

    private const int HISTORY_DAYS = 30;

    public function index(Translations $translations, AdminServerStatus $status): Response
    {
        $server = Server::query()->where('is_active', true)->orderBy('id')->first();

        return Inertia::render('admin/servers', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'server' => $server === null ? null : $status->detail($server),
            'applications' => $server === null ? null : $this->applications($server),
            'events' => $server === null ? null : $this->events($server),
        ]);
    }

    public function planning(UpdatePlanningPausedRequest $request, Server $server, SetPlanningPaused $setPlanningPaused): RedirectResponse
    {
        abort_unless($server->is_active, 404);

        $user = $request->user();
        assert($user instanceof User);

        $setPlanningPaused->handle($user, $server, $request->paused());

        return back();
    }

    public function reapply(Request $request, Server $server, ReapplyServerConfig $reapplyServerConfig): RedirectResponse
    {
        abort_unless($server->is_active, 404);

        $user = $request->user();
        assert($user instanceof User);

        $result = $reapplyServerConfig->handle($user, $server);

        Inertia::flash('toast', [
            'type' => match ($result) {
                ReconcileResult::Applied => 'success',
                ReconcileResult::Failed => 'error',
                default => 'warning',
            },
            'message' => __('admin.servers.reapplyResult.'.$result->value),
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function applications(Server $server): array
    {
        $page = ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('started_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: 'applications_page')
            ->withQueryString()
            ->through(fn (ConfigApplication $application): array => [
                'id' => $application->id,
                'block_key' => $application->block_key,
                'attempt' => $application->attempt,
                'status' => $application->status->value,
                'error' => $application->status === ConfigApplicationStatus::Failed ? $application->error : null,
                'started_at' => $application->started_at->toIso8601String(),
                'finished_at' => $application->finished_at?->toIso8601String(),
            ]);

        return $this->paginated($page);
    }

    /**
     * @return array<string, mixed>
     */
    private function events(Server $server): array
    {
        $page = IntegrationEvent::query()
            ->where('server_id', $server->id)
            ->where('created_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: 'events_page')
            ->withQueryString()
            ->through(fn (IntegrationEvent $event): array => [
                'id' => $event->id,
                'type' => $event->type->value,
                'summary' => $event->summary,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return $this->paginated($page);
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  LengthAwarePaginator<int, TRow>  $page
     * @return array<string, mixed>
     */
    private function paginated(LengthAwarePaginator $page): array
    {
        return [
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'prev_url' => $page->previousPageUrl(),
            'next_url' => $page->nextPageUrl(),
        ];
    }
}
