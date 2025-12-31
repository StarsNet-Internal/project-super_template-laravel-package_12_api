<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\HasMany;

// Traits
use App\Models\Traits\ObjectIDTrait;

class Product extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_products';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_products';
    }

    // Attributes
    protected $attributes = [
        // Default
        'name' => [
            'zh' => null,
            'en' => null,
            'cn' => null,
        ],
        'description' => [
            'zh' => null,
            'en' => null,
            'cn' => null,
        ],
        'price' => 0,
        'image_url' => null,
        'category' => null,
        'rating' => 0.0,
        'reviews_count' => 0,
        'stock_quantity' => 0,
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

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock_quantity', '>', 0);
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'item.product_id');
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

    public function decreaseStock(int $quantity = 1): bool
    {
        if ($this->stock_quantity < $quantity) {
            return false;
        }
        $this->stock_quantity -= $quantity;
        return $this->save();
    }

    public function increaseStock(int $quantity = 1): bool
    {
        $this->stock_quantity += $quantity;
        return $this->save();
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}

