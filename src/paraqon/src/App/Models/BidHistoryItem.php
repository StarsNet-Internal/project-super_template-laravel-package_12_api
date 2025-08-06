<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;

// Traits
use App\Models\Traits\ObjectIDTrait;

class BidHistoryItem extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';

    // Attributes
    protected $attributes = [
        // Relationships
        'winning_bid_customer_id' => null,

        // Default
        'current_bid' => null,

        // Timestamps
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}
