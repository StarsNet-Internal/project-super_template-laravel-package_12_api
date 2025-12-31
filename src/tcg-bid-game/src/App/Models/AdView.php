<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

class AdView extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_ad_views';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_ad_views';
    }

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        // Default
        'energy_earned' => 0,
        'ad_provider' => null, // google_ads, unity_ads, etc.
        'status' => 'pending', // pending, completed, failed
        'started_at' => null,
        'viewed_at' => null,
        'completed_at' => null,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Scope Begins
    // -----------------------------

    public function scopeByCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('viewed_at', '>=', now()->startOfDay())
              ->orWhere('completed_at', '>=', now()->startOfDay());
        });
    }

    public function scopeByDate(Builder $query, $date): Builder
    {
        return $query->where(function ($q) use ($date) {
            $q->where(function ($q2) use ($date) {
                $q2->where('viewed_at', '>=', $date->startOfDay())
                   ->where('viewed_at', '<=', $date->endOfDay());
            })->orWhere(function ($q2) use ($date) {
                $q2->where('completed_at', '>=', $date->startOfDay())
                   ->where('completed_at', '<=', $date->endOfDay());
            });
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            GameUser::class,
            'customer_id'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Accessor Begins
    // -----------------------------

    // -----------------------------
    // Accessor Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateCustomer(GameUser $customer): bool
    {
        $this->customer()->associate($customer);
        return $this->save();
    }

    public function markAsCompleted(): bool
    {
        $this->status = 'completed';
        $this->completed_at = now();
        if (!$this->viewed_at) {
            $this->viewed_at = now();
        }
        return $this->save();
    }

    public function markAsFailed(): bool
    {
        $this->status = 'failed';
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}

