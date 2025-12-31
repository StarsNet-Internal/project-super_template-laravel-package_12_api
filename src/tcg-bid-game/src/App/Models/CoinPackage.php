<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;

// Traits
use App\Models\Traits\ObjectIDTrait;

class CoinPackage extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_coin_packages';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_coin_packages';
    }

    // Attributes
    protected $attributes = [
        // Default
        'amount' => 0,
        'price_usd' => 0.0,
        'price_display' => null,
        'badge_text' => null,
        'icon_type' => 'Coins', // Coins, Star, Sparkles, Zap, Crown
        'color_gradient' => null,
        'display_order' => 0,
        'is_active' => true,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Scope Begins
    // -----------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order', 'asc');
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

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

    public function isAvailable(): bool
    {
        return $this->is_active;
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}

