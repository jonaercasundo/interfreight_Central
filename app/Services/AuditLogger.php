<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record(string $action, ?object $target = null, array $metadata = []): void
    {
        $request = app()->bound('request') ? request() : null;
        AuditLog::create([
            'actor_id' => Auth::id(), 'action' => $action,
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'metadata' => $this->safeMetadata(array_slice($metadata, 0, 20, true)),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/password|token|secret|authorization|api.?key|credential/i', (string) $key)) {
                continue;
            }
            if (is_array($value)) {
                $permissionSnapshot = in_array($key, ['before', 'after'], true)
                    && array_is_list($value)
                    && count($value) <= 1000
                    && collect($value)->every(static fn ($item) => is_string($item));
                $value = $permissionSnapshot
                    ? array_map(static fn (string $permission) => mb_substr($permission, 0, 200), $value)
                    : $this->limitValue($value);
            }
            if (is_scalar($value) || $value === null || is_array($value)) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 300) : $value;
            }
        }

        return $safe;
    }

    private function limitValue(array $value): array
    {
        $result = [];
        foreach (array_slice($value, 0, 30, true) as $key => $item) {
            if (preg_match('/password|token|secret|authorization|api.?key|credential/i', (string) $key)) {
                continue;
            }
            if (is_array($item)) {
                $item = $this->limitValue($item);
            }
            if (is_string($item)) {
                $item = mb_substr($item, 0, 200);
            }
            if (is_scalar($item) || $item === null || is_array($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
