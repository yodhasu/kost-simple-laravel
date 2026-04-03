<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'kost_id',
        'tenant_id',
        'category',
        'amount',
        'transaction_date',
        'description',
        'created_at',
        'region_id',
        'financial_class',
        'is_frozen',
        'reference_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'transaction_date' => 'date',
        'is_frozen' => 'boolean',
    ];

    public function kost(): BelongsTo
    {
        return $this->belongsTo(Kost::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
