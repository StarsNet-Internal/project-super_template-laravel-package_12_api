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
use App\Models\Product;
use App\Models\ProductVariant;

class PassedAuctionRecord extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'passed_auction_records';

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        'product_id' => null,
        'product_variant_id' => null,
        'auction_lot_id' => null,
        // Default
        'remarks' => null,
        // Booleans
        'is_disabled' => false,
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
            Customer::class
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
