<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Models
use App\Models\Customer;

class WatchlistItem extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'watchlist_items';

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        'item_type' => null,
        'item_id' => null,
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
            'customer'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------
}
