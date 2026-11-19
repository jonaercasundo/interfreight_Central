<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActualExpense extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_amount' => 'decimal:2'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExpenseDocument::class, 'expense_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExpenseEvent::class, 'expense_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ExpenseAdjustment::class, 'expense_id');
    }
}
