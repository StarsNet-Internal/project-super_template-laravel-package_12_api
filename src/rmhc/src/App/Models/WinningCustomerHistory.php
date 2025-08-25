<?php

namespace Starsnet\Project\Rmhc\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

// Models
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

class WinningCustomerHistory extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'winning_customer_histories';

    // Attributes
    protected $attributes = [
        // Relationships
        'auction_lot_id' => null,

        // Default
        'winning_customers' => [],
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function auctionLot(): BelongsTo
    {
        return $this->belongsTo(
            AuctionLot::class,
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------
}
