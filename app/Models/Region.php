<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'name',
        'created_at',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_regions')
            ->withPivot('assigned_at');
    }

    public function kosts(): HasMany
    {
        return $this->hasMany(Kost::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
