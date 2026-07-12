<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySmsSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'provider',
        'sender_id',
        'api_key',
        'notifications_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'notifications_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
