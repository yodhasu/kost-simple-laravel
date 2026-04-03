<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'kost_id',
        'name',
        'phone',
        'start_date',
        'dp_due_date',
        'end_date',
        'rent_price',
        'prepaid_balance',
        'paid_until',
        'status',
        'is_active',
        'created_at',
        'trash_fee',
        'security_fee',
        'admin_fee',
    ];

    protected $casts = [
        'start_date' => 'date',
        'dp_due_date' => 'date',
        'end_date' => 'date',
        'paid_until' => 'date',
        'rent_price' => 'integer',
        'prepaid_balance' => 'integer',
        'trash_fee' => 'integer',
        'security_fee' => 'integer',
        'admin_fee' => 'integer',
        'is_active' => 'boolean',
    ];

    public function kost(): BelongsTo
    {
        return $this->belongsTo(Kost::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
