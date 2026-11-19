<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAdvance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['category_codes' => 'array', 'required_by' => 'date', 'requested_amount' => 'decimal:2', 'approved_amount' => 'decimal:2'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(CashAdvanceDisbursement::class);
    }

    public function liquidations(): HasMany
    {
        return $this->hasMany(CashAdvanceLiquidation::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(CashAdvanceReturn::class);
    }
}
