<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * Bitácora de operaciones sensibles (audit_logs, append-only).
 *
 * $metadata solo debe llevar IDs, estados y cantidades: NUNCA datos
 * personales del paciente (RN-10).
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(?User $user, string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip' => Context::get('ip'),
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }
}
