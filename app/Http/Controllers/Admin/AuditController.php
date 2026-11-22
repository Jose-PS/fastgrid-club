<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditIndexRequest;
use App\Models\AuditLog;
use App\Support\Translations;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(AuditIndexRequest $request, Translations $translations): Response
    {
        $action = $request->validated('action');
        $from = $request->validated('from');
        $to = $request->validated('to');

        $entries = AuditLog::query()
            ->with('actor:id,name')
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'created_at' => $entry->created_at?->toIso8601String(),
                'actor' => $entry->actor?->name,
                'action' => $entry->action,
                'subject' => $entry->subject_type === null
                    ? null
                    : class_basename($entry->subject_type).($entry->subject_id === null ? '' : " #{$entry->subject_id}"),
                'before' => $entry->before,
                'after' => $entry->after,
            ]);

        return Inertia::render('admin/audit', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'entries' => [
                'data' => $entries->items(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
                'prev_url' => $entries->previousPageUrl(),
                'next_url' => $entries->nextPageUrl(),
            ],
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
            'filters' => [
                'action' => $action ?? '',
                'from' => $from ?? '',
                'to' => $to ?? '',
            ],
        ]);
    }
}
