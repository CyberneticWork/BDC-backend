<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\UserActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ActivityLogReportController extends Controller
{
    public function index(Request $request)
    {
        UserActivityLogger::ensureTable();
        if (!Schema::hasTable('user_activity_logs')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'user_id' => 'nullable|integer',
            'module' => 'nullable|string|max:80',
            'action' => 'nullable|string|max:60',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:200',
            'page' => 'nullable|integer|min:1',
        ]);

        $q = UserActivityLog::query()->orderByDesc('id');

        if (!empty($validated['from_date'])) {
            $q->whereDate('created_at', '>=', $validated['from_date']);
        }
        if (!empty($validated['to_date'])) {
            $q->whereDate('created_at', '<=', $validated['to_date']);
        }
        if (!empty($validated['user_id'])) {
            $q->where('user_id', (int) $validated['user_id']);
        }
        if (!empty($validated['module'])) {
            $q->where('module', $validated['module']);
        }
        if (!empty($validated['action'])) {
            $q->where('action', $validated['action']);
        }
        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $q->where(function ($w) use ($s) {
                $w->where('user_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%")
                    ->orWhere('path', 'like', "%{$s}%")
                    ->orWhere('ip', 'like', "%{$s}%");
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = $q->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function filters()
    {
        UserActivityLogger::ensureTable();
        $modules = [];
        $actions = [];
        if (Schema::hasTable('user_activity_logs')) {
            $modules = UserActivityLog::query()->select('module')->distinct()->orderBy('module')->pluck('module');
            $actions = UserActivityLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');
        }

        $users = User::query()
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name ?: $u->email,
                'role' => $u->role,
            ]);

        return response()->json([
            'modules' => $modules,
            'actions' => $actions,
            'users' => $users,
        ]);
    }
}
