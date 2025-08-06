<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\EmbedsMany;

// Traits
use App\Models\Traits\ObjectIDTrait;

class BidHistory extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'bid_histories';

    // Attributes
    protected $attributes = [
        // Relationships
        'auction_lot_id' => null,
        'current_bid' => null,

        // Default
        'histories' => [],

        // Timestamps
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

    public function histories(): EmbedsMany
    {
        return $this->embedsMany(
            BidHistoryItem::class,
            'histories'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateAuctionLot(AuctionLot $lot): bool
    {
        $this->auctionLot()->associate($lot);
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
