<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['company_name', 'contact_name', 'email', 'phone', 'billing_address', 'client_type', 'sales_owner_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(ClientInquiry::class);
    }
}
