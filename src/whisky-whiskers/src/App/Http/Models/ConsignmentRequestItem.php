<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;

// Traits
use App\Models\Traits\ObjectIDTrait;

class ConsignmentRequestItem extends Model
{
    use ObjectIDTrait;

    protected $connection = 'mongodb';
    protected $attributes = [
        // Default
        'title' => null,
        'description' => null,
        'images' => [],

        'is_approved' => false,
        'evaluated_price' => 0,
        'evaluated_currency' => 'HKD',
        'rejection_reason' => null,
        'remarks' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}
