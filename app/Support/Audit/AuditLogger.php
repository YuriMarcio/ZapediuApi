<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(string $action, array $context = [], ?Request $request = null): AuditLog
    {
        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);

        return AuditLog::query()->create([
            'company_id' => $context['company_id'] ?? $tenant->companyId(),
            'user_id' => $context['user_id'] ?? auth()->id(),
            'action' => $action,
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'ip_address' => $request?->ip() ?? request()->ip(),
            'user_agent' => $request?->userAgent() ?? request()->userAgent(),
            'changes' => $context['changes'] ?? null,
            'metadata' => $context['metadata'] ?? null,
        ]);
    }
}
