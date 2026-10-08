<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A MoMo charge started over USSD whose final outcome must be reported back to the
 * Ogua USSD platform. The key is the platform's transaction id (= client_reference).
 */
class UssdPaymentReport extends Model
{
    protected $primaryKey = 'client_reference';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_reference',
        'reported_status',
        'reported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
        ];
    }
}
