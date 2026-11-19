<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostEstimate extends Model
{
    protected $fillable = ['reference', 'inquiry_id', 'forecast_id', 'sales_owner_id', 'parent_estimate_id', 'revision_reason', 'version', 'created_by', 'updated_by', 'shipment_classification', 'transport_mode', 'origin', 'destination', 'planned_window', 'currency', 'assumptions', 'status', 'direct_cost_total', 'indirect_cost_total', 'contingency_total', 'estimated_cost_total', 'funding_required_total', 'recoverable_total', 'nonrecoverable_total', 'reviewed_by', 'reviewed_at', 'review_remarks', 'lock_version'];

    protected function casts(): array
    {
        return ['planned_window' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function getAvailableForQuotationAttribute(): bool
    {
        return $this->status === 'approved' && $this->forecast?->status === 'active';
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ClientInquiry::class);
    }

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(ShipmentForecast::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CostEstimateLine::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }
}
