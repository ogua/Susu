<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'logo',
        'name',
        'description',
        'slug',
        'domain_alias',
        'website',
        'primary_color',
        'secondary_color',
        'address',
        'contact_email',
        'contact_phone',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function smsSetting(): HasOne
    {
        return $this->hasOne(CompanySmsSetting::class);
    }

    public function paymentSetting(): HasOne
    {
        return $this->hasOne(CompanyPaymentSetting::class);
    }
}
