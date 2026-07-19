<?php

namespace App\Models;

use Database\Factories\CustomerFamilyMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFamilyMember extends Model
{
    /** @use HasFactory<CustomerFamilyMemberFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'name',
        'relationship',
        'contact_phone',
        'occupation',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
