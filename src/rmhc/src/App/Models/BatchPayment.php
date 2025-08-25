<?php

namespace Starsnet\Project\Rmhc\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

// Models
use App\Models\Customer;

class BatchPayment extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'batch_paynments';

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,

        // Default
        'order_ids' => [],
        'total_amount' => null,
        'currency' => 'HKD',
        'payment_method' => null,
        'client_secret' => null,
        'payment_intent_id' => null,
        'api_response' => null,
        'payment_image' => null,
        'payment_image_uploaded_at' => null,
        'is_approved' => null,
        'is_cancelled' => null,
        'payment_received_at' => null,

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

    // -----------------------------
    // Relationship Ends
    // -----------------------------
}
