<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request)
    {
        $logs = ActivityLog::with(['user', 'subject'])
            ->when($request->q, fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->date_from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('activity-logs.index', [
            'logs' => $logs,
            'users' => User::orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
