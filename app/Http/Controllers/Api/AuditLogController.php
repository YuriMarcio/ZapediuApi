<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->withoutGlobalScopes()
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $logs]);
    }
}
