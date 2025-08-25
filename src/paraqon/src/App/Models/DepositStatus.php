<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;

// Traits
use App\Models\Traits\ObjectIDTrait;

class DepositStatus extends Model
{
    // Connection
    protected $connection = 'mongodb';

    // Attributes
    protected $attributes = [
        // Default
        'slug' => null,
        'remarks' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
}
