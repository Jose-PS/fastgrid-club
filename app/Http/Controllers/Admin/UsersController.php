<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsersIndexRequest;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use App\Support\Translations;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    public function index(UsersIndexRequest $request, Translations $translations): Response
    {
        $search = $request->validated('search');
        $driversTable = (new Driver)->getTable();
        $lapsTable = (new Lap)->getTable();

        $users = User::query()
            ->withExists(['googleIdentity as has_google', 'steamIdentity as has_steam'])
            ->withCount('passkeys')
            ->addSelect([
                'votes_count' => Vote::query()->selectRaw('count(*)')->whereColumn('user_id', 'users.id'),
                'proposals_count' => Proposal::query()->selectRaw('count(*)')->whereColumn('user_id', 'users.id'),
                'driver_names' => DB::table($driversTable)
                    ->selectRaw("string_agg(last_name, ', ' order by id)")
                    ->whereColumn('user_id', 'users.id'),
                'laps_count' => DB::table($lapsTable)
                    ->selectRaw('count(*)')
                    ->whereIn('driver_id', fn (Builder $query) => $query->select('id')->from($driversTable)->whereColumn('user_id', 'users.id')),
            ])
            ->when($search, function ($query, string $term): void {
                $like = '%'.addcslashes($term, '\\%_').'%';

                $query->where(fn ($inner) => $inner
                    ->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'has_google' => (bool) $user->getAttribute('has_google'),
                'has_steam' => (bool) $user->getAttribute('has_steam'),
                'passkeys_count' => (int) $user->getAttribute('passkeys_count'),
                'driver_names' => $user->getAttribute('driver_names'),
                'laps_count' => (int) $user->getAttribute('laps_count'),
                'votes_count' => (int) $user->getAttribute('votes_count'),
                'proposals_count' => (int) $user->getAttribute('proposals_count'),
                'is_admin' => $user->is_admin,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/users', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'users' => [
                'data' => $users->items(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'prev_url' => $users->previousPageUrl(),
                'next_url' => $users->nextPageUrl(),
            ],
            'filters' => [
                'search' => $search ?? '',
            ],
        ]);
    }
}
