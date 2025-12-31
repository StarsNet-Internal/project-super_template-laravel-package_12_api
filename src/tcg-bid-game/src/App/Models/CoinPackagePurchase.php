<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

class CoinPackagePurchase extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_coin_package_purchases';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_coin_package_purchases';
    }

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        'coin_package_id' => null,
        // Default
        'status' => 'pending', // pending, completed, failed
        'payment_method' => 'stripe', // stripe, apple_iap, google_iap
        'stripe_payment_intent_id' => null,
        'stripe_client_secret' => null,
        'iap_receipt' => null, // Apple/Google receipt data
        'iap_transaction_id' => null, // Apple/Google transaction ID
        'iap_product_id' => null, // Apple/Google product ID
        'amount_usd' => 0.0,
        'coins_amount' => 0,
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

    public function coinPackage(): BelongsTo
    {
        return $this->belongsTo(
            CoinPackage::class,
            'coin_package_id'
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateCustomer(GameUser $customer): bool
    {
        $this->customer()->associate($customer);
        return $this->save();
    }

    public function associateCoinPackage(CoinPackage $coinPackage): bool
    {
        $this->coinPackage()->associate($coinPackage);
        return $this->save();
    }

    public function markAsCompleted(): bool
    {
        $this->status = 'completed';
        $this->completed_at = now();
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

