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
        'difficulty_params' => [], // object: game difficulty config (e.g. round_time_sec, max_rounds)
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

    /**
     * Get difficulty_params for API response.
     * For games with levels_by_difficulty + difficulty: returns one random level (2D array).
     * Otherwise returns the stored difficulty_params as-is.
     */
    public function getResolvedDifficultyParams(): array
    {
        $levelsByDifficulty = $this->getAttribute('levels_by_difficulty');
        $difficulty = $this->getAttribute('difficulty');

        if (
            is_array($levelsByDifficulty)
            && is_string($difficulty)
            && isset($levelsByDifficulty[$difficulty])
            && is_array($levelsByDifficulty[$difficulty])
        ) {
            $levels = $levelsByDifficulty[$difficulty];
            if (count($levels) > 0) {
                $index = array_rand($levels);

                return $levels[$index];
            }
        }

        $params = $this->getAttribute('difficulty_params');

        return is_array($params) ? $params : [];
    }

    /**
     * Whether this game uses level-based difficulty (levels_by_difficulty + difficulty).
     */
    public function hasLevelBasedDifficulty(): bool
    {
        $levelsByDifficulty = $this->getAttribute('levels_by_difficulty');
        $difficulty = $this->getAttribute('difficulty');

        return is_array($levelsByDifficulty)
            && is_string($difficulty)
            && isset($levelsByDifficulty[$difficulty])
            && is_array($levelsByDifficulty[$difficulty])
            && count($levelsByDifficulty[$difficulty]) > 0;
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
