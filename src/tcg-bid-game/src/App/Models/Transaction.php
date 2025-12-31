<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

class Transaction extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_transactions';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_transactions';
    }

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        // Default
        'transaction_type' => null, // game_earnings, product_purchase, coin_purchase, ad_reward
        'amount' => 0, // Positive for income, negative for expense
        'balance_after' => 0,
        'currency_type' => 'coins', // coins, energy
        'reference_id' => null, // game_session_id, order_id, coin_package_id, ad_view_id
        'reference_type' => null, // game_session, order, coin_package, ad_view
        'description' => [
            'zh' => null,
            'en' => null,
            'cn' => null,
        ],
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

    public function scopeByCurrencyType(Builder $query, string $currencyType): Builder
    {
        return $query->where('currency_type', $currencyType);
    }

    public function scopeByTransactionType(Builder $query, string $transactionType): Builder
    {
        return $query->where('transaction_type', $transactionType);
    }

    public function scopeByCurrencyAndTransactionType(Builder $query, ?string $currencyType, ?string $transactionType): Builder
    {
        if ($currencyType) {
            $query->where('currency_type', $currencyType);
        }
        if ($transactionType) {
            $query->where('transaction_type', $transactionType);
        }
        return $query;
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

    // -----------------------------
    // Action Ends
    // -----------------------------
}

