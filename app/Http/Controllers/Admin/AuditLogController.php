<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audit log viewer — ROUTES.md `GET /admin/audit-logs`, SECURITY.md section 5.
 *
 * Read-only by construction: this controller has one action and it is a GET.
 * The model itself refuses update and delete.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);

        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('actor')
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['subject'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('remarks', 'like', '%'.addcslashes($v, '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'filters' => $f,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'subjects' => AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
            'actors' => User::query()->whereIn('id', AuditLog::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
