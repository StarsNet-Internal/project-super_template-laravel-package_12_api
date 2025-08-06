<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;

// Traits
use App\Models\Traits\ObjectIDTrait;

class ConsignmentRequestItem extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';

    // Attributes
    protected $attributes = [
        // Relationships
        'product_id' => null,
        'product_variant_id' => null,

        // Default
        'title' => null,
        'description' => null,
        'images' => [],
        'videos' => [],
        'certificates' => [],

        'is_approved' => false,
        'evaluated_price' => 0,
        'evaluated_currency' => 'HKD',
        'rejection_reason' => null,
        'remarks' => null

        // Timestamps
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}
