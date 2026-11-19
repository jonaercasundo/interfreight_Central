<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = ['reference', 'job_number', 'creation_key', 'client_id', 'inquiry_id', 'forecast_id', 'quotation_id', 'commercial_baseline_id', 'created_by', 'customer_name', 'origin', 'destination', 'mode', 'status', 'master_status', 'source_type', 'originating_department', 'shipment_type', 'service_type', 'lcl_fcl', 'cargo', 'weight_kg', 'quantity', 'volume_cbm', 'estimated_delivery', 'planned_departure', 'actual_departure', 'freight_cost', 'currency', 'notes', 'cancellation_reason', 'archived_at', 'lock_version'];

    protected function casts(): array
    {
        return ['estimated_delivery' => 'date', 'planned_departure' => 'date', 'actual_departure' => 'date', 'weight_kg' => 'decimal:2', 'freight_cost' => 'decimal:2', 'quantity' => 'decimal:3', 'volume_cbm' => 'decimal:3', 'archived_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ClientInquiry::class);
    }

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(ShipmentForecast::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(CommercialBaseline::class, 'commercial_baseline_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShipmentDepartmentAssignment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentTimelineEvent::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ShipmentMilestone::class);
    }

    public function customsProcess(): HasMany
    {
        return $this->hasMany(ShipmentCustomsProcess::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(ShipmentTransportTrip::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ShipmentForwardingBooking::class);
    }

    public function containers(): HasMany
    {
        return $this->hasMany(ShipmentContainer::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ShipmentDelivery::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ShipmentException::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ShipmentStatusHistory::class);
    }

    public function actualExpenses(): HasMany
    {
        return $this->hasMany(ActualExpense::class);
    }

    public function cashAdvances(): HasMany
    {
        return $this->hasMany(CashAdvance::class);
    }
}
