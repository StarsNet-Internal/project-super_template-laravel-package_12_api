<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

// Models
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;

class Bid extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $collection = 'bids';

    protected $attributes = [
        // Relationships
        'auction_lot_id' => null,
        'customer_id' => null,
        'store_id' => null,
        'product_id' => null,
        'product_variant_id' => null,

        // Default
        'bid' => 0,
        'is_hidden' => false,

        // Timestamps
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(
            Store::class,
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

    public function auctionLot(): BelongsTo
    {
        return $this->belongsTo(
            AuctionLot::class,
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateStore(Store $store): bool
    {
        $this->store()->associate($store);
        return $this->save();
    }

    public function associateCustomer(Customer $customer): bool
    {
        $this->customer()->associate($customer);
        return $this->save();
    }

    public function associateProduct(Product $product): bool
    {
        $this->product()->associate($product);
        return $this->save();
    }

    public function associateProductVariant(ProductVariant $variant): bool
    {
        $this->productVariant()->associate($variant);
        return $this->save();
    }

    public function associateAuctionLot(AuctionLot $lot): bool
    {
        $this->auctionLot()->associate($lot);
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
