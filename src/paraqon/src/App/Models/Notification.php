<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\Status;

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
        'description' => null,
        'kind' => 'system',
        // Booleans
        'is_read' => false,
        // Timestamps
        'deleted_at' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];
    protected $casts = [
        'is_read' => 'boolean',
        'sent_at' => 'datetime',
        'admin_archived_at' => 'datetime',
        'admin_deleted_at' => 'datetime',
    ];

    public function scopeVisibleToAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('account_id', $accountId)
            ->where('status', '!=', Status::DELETED->value)
            ->whereNull('deleted_at');
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        // Legacy records have no kind and are automated system notifications.
        return $kind === 'direct'
            ? $query->where('kind', 'direct')
            : $query->where('kind', '!=', 'direct');
    }

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
