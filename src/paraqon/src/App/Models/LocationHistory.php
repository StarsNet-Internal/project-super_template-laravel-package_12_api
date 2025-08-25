<?php

namespace StarsNet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

// Models
use App\Models\Product;

class LocationHistory extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $collection = 'location_histories';

    protected $attributes = [
        // Relationships
        'product_id' => null,

        // Default
        'purpose' => [
            'purpose' => 'PRIVATE_SALE',
            'remarks' => ''
        ],
        'status' => [
            'status' => '',
            'remarks' => ''
        ],
        'location' => [
            'location' => 'IN_OFFICE',
            'remarks' => ''
        ],

        // Timestamps
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Relationship Begins
    // -----------------------------

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
    // Action Begins
    // -----------------------------

    public function associateProduct(Product $product): bool
    {
        $this->product()->associate($product);
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
