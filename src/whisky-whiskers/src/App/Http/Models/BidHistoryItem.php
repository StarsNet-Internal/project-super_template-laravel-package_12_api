<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;

// Traits
use App\Models\Traits\ObjectIDTrait;

class BidHistoryItem extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $attributes = [
        // Relationships
        'winning_bid_customer_id' => null,
        // Default
        'current_bid' => null,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}
