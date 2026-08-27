<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    /**
     * CEO-only guard. Every method funnels through here.
     */
    protected function assertCeo(): void
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'ceo') {
            abort(403, 'Only the CEO can view audit logs.');
        }
    }

    public function index(Request $request)
    {
        $this->assertCeo();

        $query = AuditLog::with('user')->orderByDesc('created_at');

        // Filter: entity type (short class name)
        if ($request->filled('type')) {
            $type = $request->input('type');
            // Accept either short name (Shift) or fully-qualified.
            if (!str_contains($type, '\\')) {
                $type = 'App\\Models\\' . $type;
            }
            $query->where('auditable_type', $type);
        }

        // Filter: action
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        // Filter: user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Filter: date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Filter: search in description
        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where('description', 'like', "%{$q}%");
        }

        $logs = $query->paginate(50)->withQueryString();

        // Distinct types + users for filter dropdowns.
        $types = AuditLog::query()
            ->select('auditable_type')
            ->distinct()
            ->orderBy('auditable_type')
            ->pluck('auditable_type')
            ->map(fn($t) => class_basename($t))
            ->unique()
            ->values();

        $users = User::orderBy('name')->get(['id', 'name']);

        return view('audit-logs.index', compact('logs', 'types', 'users'));
    }

    public function show(AuditLog $auditLog)
    {
        $this->assertCeo();
        $auditLog->load('user');
        return view('audit-logs.show', ['log' => $auditLog]);
    }
}
