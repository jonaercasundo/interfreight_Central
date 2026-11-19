<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMonthlyBudget extends Model
{
    protected $fillable = ['year', 'month', 'budget_php', 'warning_percent', 'critical_percent', 'updated_by'];

    protected function casts(): array
    {
        return ['budget_php' => 'decimal:2', 'year' => 'integer', 'month' => 'integer'];
    }
}
