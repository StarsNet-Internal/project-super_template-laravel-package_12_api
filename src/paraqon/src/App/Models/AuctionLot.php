<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\HasOne;
use MongoDB\Laravel\Relations\HasMany;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\LiveSale\CurrentBidCalculation;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;

class AuctionLot extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'auction_lots';

    // Attributes
    protected $attributes = [
        // Relationships
        'auction_request_id' => null,
        'owned_by_customer_id' => null,
        'product_id' => null,
        'product_variant_id' => null,
        'store_id' => null,
        'latest_bid_customer_id' => null,
        'winning_bid_customer_id' => null,

        // Default
        'starting_price' => 0,
        'reserve_price' => 0,
        'current_bid' => 0,
        'permission_type' => null,
        'permission_requests' => [],

        'status' => Status::ACTIVE->value,
        'reply_status' => ReplyStatus::PENDING->value,
        'remarks' => null,

        // Booleans
        'is_disabled' => false,
        'is_closed' => false,
        'is_permission_required' => false,
        'is_paid' => false,
        'is_bid_placed' => false,

        'start_datetime' => null,
        'end_datetime' => null,
        // Timestamps
        'deleted_at' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function auctionRequest(): BelongsTo
    {
        return $this->belongsTo(
            AuctionRequest::class,
        );
    }

    public function ownedCustomer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class,
            'owned_by_customer_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class,
        );
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
        );
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(
            Store::class,
        );
    }

    public function latestBidCustomer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class,
            'latest_bid_customer_id'
        );
    }

    public function winningBidCustomer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class,
            'winning_bid_customer_id'
        );
    }

    public function bids(): HasMany
    {
        return $this->hasMany(
            Bid::class
        );
    }

    public function passedAuctionRecords(): HasMany
    {
        return $this->hasMany(
            PassedAuctionRecord::class
        );
    }

    public function bidHistory(): HasOne
    {
        return $this->hasOne(
            BidHistory::class,
            'auction_lot_id'
        );
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(
            WatchlistItem::class,
            'item_id'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function getCurrentMaximumBidValue(
        $allBids,
        $bidHistory,
        $newBidCustomerID = null,
        $newBidValue = null,
        $bidType = null
    ) {
        return CurrentBidCalculation::maximumValue(
            CurrentBidCalculation::rowsFromBids($allBids),
            $this->starting_price,
            $this->reserve_price,
            CurrentBidCalculation::incrementsFrom($this->bid_incremental_settings),
            $newBidCustomerID,
            $newBidValue,
            $bidType,
            $this->lastHistoryRow($bidHistory)
        );
    }

    public function getCurrentBidPrice(
        $isCalculationNeeded = false,
        $newBidCustomerID = null,
        $newBidValue = null,
        $bidType = null
    ) {
        // Ensure BidHistory exists
        $auctionLotId = $this->id;
        $bidHistory = BidHistory::where('auction_lot_id', $auctionLotId)->first();
        $startingPrice = $this->starting_price;

        if ($bidHistory == null) {
            $bidHistory = BidHistory::create([
                'auction_lot_id' => $auctionLotId,
                'current_bid' => $startingPrice,
                'histories' => []
            ]);
        }

        // Return price
        if (!$isCalculationNeeded) {
            if ($bidHistory->histories()->count() == 0) return $startingPrice;
            return $bidHistory->current_bid;
        }

        // Get all bids
        $allBids = $this->bids()
            ->where('is_hidden', false)
            ->orderByDesc('bid')
            ->orderBy('created_at')
            ->get();

        return CurrentBidCalculation::price(
            CurrentBidCalculation::rowsFromBids($allBids),
            $this->starting_price,
            $this->reserve_price,
            CurrentBidCalculation::incrementsFrom($this->bid_incremental_settings),
            $newBidCustomerID,
            $newBidValue,
            $bidType,
            $this->lastHistoryRow($bidHistory)
        );
    }

    /**
     * @return array{winning_bid_customer_id: mixed, current_bid: mixed}|null
     */
    private function lastHistoryRow(mixed $bidHistory): ?array
    {
        if ($bidHistory === null || $bidHistory->histories()->count() === 0) {
            return null;
        }
        $winningBid = $bidHistory->histories()->last();
        if ($winningBid === null) {
            return null;
        }
        return [
            'winning_bid_customer_id' => $winningBid->winning_bid_customer_id,
            'current_bid' => $winningBid->current_bid,
        ];
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
