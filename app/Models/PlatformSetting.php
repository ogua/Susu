<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One operator-editable setting; read through App\Services\PlatformSettings. */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
    ];
}
