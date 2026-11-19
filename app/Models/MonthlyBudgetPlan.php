<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyBudgetPlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['budget_month' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }
}
