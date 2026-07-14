<?php

namespace App\Models;

use App\Enums\GroupRoundStatus;
use App\Enums\GroupStatus;
use App\Enums\LoanFrequency;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'liability_account_id',
        'created_by',
        'name',
        'code',
        'contribution_amount',
        'frequency',
        'status',
        'activated_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contribution_amount' => 'integer',
            'frequency' => LoanFrequency::class,
            'status' => GroupStatus::class,
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'liability_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class)->orderBy('rotation_position');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(GroupRound::class)->orderBy('round_number');
    }

    public function currentRound(): ?GroupRound
    {
        return $this->rounds()->where('status', '!=', GroupRoundStatus::Completed)->first();
    }
}
