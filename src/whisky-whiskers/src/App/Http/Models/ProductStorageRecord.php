<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Models
use App\Models\Customer;
use App\Models\Product;

class ProductStorageRecord extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    protected $connection = 'mongodb';
    protected $collection = 'product_storage_record';

    protected $attributes = [
        // Relationships
        'customer_id' => null,
        'product_id' => null,
        // Default
        'start_datetime' => null,
        'end_datetime' => null,
        'winning_bid' => null,
        'remarks' => null,
        // Booleans
        'is_paid' => false,
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
            Customer::class,
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class,
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Accessor Begins
    // -----------------------------

    public function getProductInfoAttribute(): array
    {
        $account = $this->requestedAccount()->first();

        return [
            'user_id' => optional($account)->user->id,
            'account_id' => optional($account)->_id,
            'username' => optional($account)->username,
            'avatar' => optional($account)->avatar
        ];
    }

    // -----------------------------
    // Accessor Ends
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

    // -----------------------------
    // Action Ends
    // -----------------------------
}
