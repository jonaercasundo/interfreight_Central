<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAdvanceLiquidation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_expense_total' => 'decimal:2', 'verified_expense_total' => 'decimal:2', 'reimbursement_due' => 'decimal:2', 'submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashAdvanceLiquidationLine::class, 'liquidation_id');
    }
}
