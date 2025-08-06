<?php

namespace Starsnet\Project\ShoppingCart\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

// Models
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;

class CustomStoreQuote extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $collection = 'custom_store_quotes';

    protected $attributes = [
        // Relationships
        'quote_order_id' => null,
        'purchase_order_id' => null,
        'product_variant_id' => null,
        'qty' => 0,
        'total_price' => 0,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Scope Begins
    // -----------------------------

    public function scopeByCustomer(Builder $query, Customer $customer): Builder
    {
        return $query->where('customer_id', $customer->_id);
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function quoteOrder(): BelongsTo
    {
        return $this->belongsTo(
            Order::class,
            'quote_order_id'
        );
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(
            Order::class,
            'purchase_order_id'
        );
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateQuoteOrder(Order $order): bool
    {
        $this->quoteOrder()->associate($order);
        return $this->save();
    }

    public function associatePurchaseOrder(Order $order): bool
    {
        $this->purchaseOrder()->associate($order);
        return $this->save();
    }

    public function associateProductVariant(ProductVariant $variant): bool
    {
        $this->productVariant()->associate($variant);
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
