<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StatutoryBillingRegistry
{
    public function active(): ?object
    {
        return DB::table('billing_issuance_profiles')->where('status', 'approved')
            ->whereNotNull('effective_from')->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('superseded_at')->orWhere('superseded_at', '>', now()))
            ->orderByDesc('version')->first();
    }

    public function issuanceReady(): bool
    {
        return (bool) config('ifc_billing.legal_issuance_verified', false)
            && $this->active() !== null
            && filled(config('ifc_billing.issuance_provider'));
    }
}
