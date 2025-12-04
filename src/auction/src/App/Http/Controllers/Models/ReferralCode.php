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
use Starsnet\Project\Auction\App\Models\ReferralCodeHistory;

class ReferralCode extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'referral_codes';

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        'code' => null,
        'quota_left' => null,

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class,
        );
    }

    public function referralCodeHistories(): HasMany
    {
        return $this->hasMany(
            ReferralCodeHistory::class,
            'item_id'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------
}
