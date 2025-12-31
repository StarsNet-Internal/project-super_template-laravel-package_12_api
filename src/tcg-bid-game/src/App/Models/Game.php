<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\HasMany;

// Traits
use App\Models\Traits\ObjectIDTrait;

class Game extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_games';

    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_games';
    }

    // Attributes
    protected $attributes = [
        // Default
        'title' => [
            'zh' => null,
            'en' => null,
            'cn' => null,
        ],
        'description' => [
            'zh' => null,
            'en' => null,
            'cn' => null,
        ],
        'image_url' => null,
        'color_gradient' => null,
        'genre' => null,
        'energy_cost' => 0,
        'coins_earned' => 0,
        'rating' => 0.0,
        'total_ratings' => 0,
        'is_new' => false,
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

    public function scopeNewGames(Builder $query): Builder
    {
        return $query->where('is_new', true);
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function gameSessions(): HasMany
    {
        return $this->hasMany(GameSession::class, 'game_id');
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

    // -----------------------------
    // Action Ends
    // -----------------------------
}
