<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = ['reference', 'inquiry_id', 'client_id', 'forecast_id', 'cost_estimate_id', 'cost_snapshot', 'sales_owner_id', 'parent_quotation_id', 'revision_reason', 'version', 'status', 'client_contact', 'service_scope', 'origin', 'destination', 'cargo_description', 'planned_window', 'currency', 'valid_until', 'terms', 'payment_terms', 'exclusions', 'assumptions', 'internal_notes', 'customer_remarks', 'subtotal', 'discount_total', 'tax_total', 'total', 'submitted_at', 'approved_at', 'approved_by', 'issued_at', 'lock_version'];

    protected function casts(): array
    {
        return ['cost_snapshot' => 'array', 'planned_window' => 'date', 'valid_until' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ClientInquiry::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(CostEstimate::class, 'cost_estimate_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quotation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(QuotationApprovalStep::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(QuotationClientResponse::class);
    }

    public function baseline(): HasMany
    {
        return $this->hasMany(CommercialBaseline::class);
    }
}
