<?php

namespace Starsnet\Project\Auction\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\HasMany;

// Traits
use App\Models\Traits\ObjectIDTrait;

// Models
use App\Models\Customer;

class ReferralCodeHistory extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'referral_code_histories';

    // Attributes
    protected $attributes = [
        // Relationships
        'owned_by_customer_id' => null,
        'used_by_customer_id' => null,
        'referral_code_id' => null,
        'code' => null,

        // Booleans
        'is_disabled' => false,
        'is_deleted' => false,

        // Timestamps
        'deleted_at' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(
            ReferralCode::class,
        );
    }

    public function ownedByCustomer(): HasMany
    {
        return $this->hasMany(
            Customer::class,
            'owned_by_customer_id'
        );
    }

    public function usedByCustomer(): HasMany
    {
        return $this->hasMany(
            Customer::class,
            'used_by_customer_id'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------
}
