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
        $startingPrice = $this->starting_price;
        $reservePrice = $this->reserve_price;

        // If customer place an ADVANCED bid, skip filtering from Case 0s
        if (is_null($bidType) || $bidType != 'ADVANCED') {
            if (count($allBids) > 2 && !is_null($newBidCustomerID)) {
                $winningBid = $bidHistory->histories()->last();
                if ($winningBid->winning_bid_customer_id == $newBidCustomerID) {
                    $currentBid = $winningBid->current_bid;
                    if ($currentBid < $newBidValue && $newBidValue < $reservePrice) return $currentBid; // Case 0A
                    if ($newBidValue > $currentBid && $currentBid > $reservePrice) return $currentBid; // Case 0B
                    if ($newBidValue >= $reservePrice && $reservePrice > $currentBid) return $reservePrice; // Case 0C
                }
            }
        }

        // Get all highest maximum bids per customer_id
        $allCustomerHighestBids = $allBids
            ->groupBy('customer_id')
            ->map(function ($item) {
                return $item->sortByDesc('bid')->first();
            })
            ->sortByDesc('bid')
            ->values();

        // Case 1: If 0 bids
        $allCustomerHighestBidsCount = $allCustomerHighestBids->count();

        if ($allCustomerHighestBidsCount === 0) return $startingPrice; // Case 1

        // If 1 bids
        $maxBidValue = $allCustomerHighestBids->max('bid');
        $isReservedPriceMet = $maxBidValue >= $reservePrice;

        if ($allCustomerHighestBidsCount === 1) {
            return $isReservedPriceMet ?
                $reservePrice : // Case 3A
                $startingPrice; // Case 2A
        }

        // If more than 1 bids
        $maxBidCount = $allCustomerHighestBids->where('bid', $maxBidValue)->count();
        if ($maxBidCount >= 2) return $maxBidValue; // Case 2B (ii) & 3B (ii)

        // For Case 2B(i) & 3B (i) Calculations
        $incrementRules = optional($this->bid_incremental_settings)['increments'];

        $maxBidValues = $allCustomerHighestBids->sortByDesc('bid')->pluck('bid')->values()->all();
        $secondHighestBidValue = $maxBidValues[1];

        $incrementalBid = 0;
        if (!is_null($incrementRules)) {
            foreach ($incrementRules as $interval) {
                if ($secondHighestBidValue >= $interval['from'] && $secondHighestBidValue < $interval['to']) {
                    $incrementalBid = $interval['increment'];
                    break;
                }
            }
        }

        if ($isReservedPriceMet) {
            // Case 3B (i)
            return max($reservePrice, min($maxBidValue, $secondHighestBidValue + $incrementalBid));
        } else {
            // Case 2B (i)
            return min($maxBidValue, $secondHighestBidValue + $incrementalBid);
        }
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


        if (in_array($bidType, ['MAX', 'ADVANCED'])) {
            $maximumMaxBidValue = $this->getCurrentMaximumBidValue(
                $allBids,
                $bidHistory,
                $newBidCustomerID,
                $newBidValue,
                $bidType
            );
            return $maximumMaxBidValue;
        }

        // get maximum Bids value
        $maximumDirectBid = $allBids
            ->first(function ($value) {
                return $value->type == 'DIRECT';
            });
        $maximumDirectBidValue = optional($maximumDirectBid)->bid;

        $maximumMaxBid = $allBids
            ->first(function ($value) {
                return in_array($value->type, ['MAX', 'ADVANCED']);
                // return $value->type == 'MAX';
            });
        $maximumMaxBidValue = optional($maximumMaxBid)->bid;

        // Validations
        if (is_null($maximumDirectBidValue) && is_null($maximumMaxBidValue)) return $this->starting_price;
        if (is_null($maximumMaxBidValue)) return $maximumDirectBidValue;
        if ($maximumDirectBidValue > $maximumMaxBidValue) return $maximumDirectBidValue; // Case 5

        if ($maximumDirectBidValue <= $maximumMaxBidValue) {
            $winningBid = $bidHistory->histories()->last();
            $winningCustomerID = $winningBid->winning_bid_customer_id;

            if ($winningCustomerID == $newBidCustomerID) return $maximumDirectBidValue; // Case 4A, 6A
        }

        // Case 4B, 6B
        $maximumMaxBidValue = $this->getCurrentMaximumBidValue(
            $allBids,
            $bidHistory,
            $newBidCustomerID,
            $newBidValue,
            $bidType
        );
        return $maximumMaxBidValue;
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
