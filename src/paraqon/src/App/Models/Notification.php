<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Models
use App\Models\Account;

class Notification extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'notifications';

    protected $attributes = [
        // Relationships
        'collection' => null,
        'document_id' => null,
        // Default
        'type' => null,
        'account_id' => null,
        'path' => null,
        'subject' => null,
        // Booleans
        'is_read' => false,
        // Timestamps
        'deleted_at' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            Account::class
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateAccount(Account $account): bool
    {
        $this->account()->associate($account);
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
