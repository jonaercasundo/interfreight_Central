<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesCapacityAvailability extends Model
{
    protected $table = 'sales_capacity_availability';

    protected $fillable = [
        'reference', 'origin', 'destination', 'transport_mode', 'service_type',
        'departure_date', 'confirmed_capacity', 'reserved_capacity', 'capacity_unit',
        'booking_cutoff', 'source_type', 'source_reference', 'created_by', 'verified_by',
        'verified_at', 'valid_from', 'valid_until', 'deactivated_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date', 'confirmed_capacity' => 'decimal:3',
            'reserved_capacity' => 'decimal:3', 'booking_cutoff' => 'datetime',
            'verified_at' => 'datetime', 'valid_from' => 'date', 'valid_until' => 'date',
            'deactivated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesCapacityEvent::class, 'availability_id');
    }

    public function getAvailableCapacityAttribute(): string
    {
        return (string) max(0, (float) $this->confirmed_capacity - (float) $this->reserved_capacity);
    }

    public function getAvailabilityStatusAttribute(): string
    {
        if ($this->deactivated_at) {
            return 'deactivated';
        }
        if (($this->valid_until && $this->valid_until->lt(today())) || $this->departure_date->lt(today())) {
            return 'expired';
        }

        return (float) $this->available_capacity > 0 ? 'available' : 'fully_reserved';
    }
}
