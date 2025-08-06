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

class CustomOrderImage extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $collection = 'custom_order_images';

    protected $attributes = [
        // Relationships
        'order_id' => null,
        'images' => [],
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            Order::class
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateOrder(Order $order): bool
    {
        $this->order()->associate($order);
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
