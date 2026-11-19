<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAdvanceLiquidationLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eligible_amount' => 'decimal:2', 'verified_amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ActualExpense::class, 'expense_id');
    }
}
