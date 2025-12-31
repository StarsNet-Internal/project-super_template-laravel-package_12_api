<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

class GameSession extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_game_sessions';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_game_sessions';
    }

    // Attributes
    protected $attributes = [
        // Relationships
        'game_id' => null,
        'customer_id' => null,
        // Default
        'energy_cost' => 0,
        'energy_spent' => 0,
        'outcome' => null, // win, lose, or null if not ended
        'coins_earned' => 0,
        'started_at' => null,
        'expire_at' => null,
        'ended_at' => null,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Scope Begins
    // -----------------------------

    public function scopeByGame(Builder $query, string $gameId): Builder
    {
        return $query->where('game_id', $gameId);
    }

    public function scopeByCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')
            ->where('expire_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expire_at', '<=', now())
            ->whereNull('ended_at');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function game(): BelongsTo
    {
        return $this->belongsTo(
            Game::class,
            'game_id'
        );
    }

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

    public function isActive(): bool
    {
        return is_null($this->ended_at) && $this->expire_at > now();
    }

    public function isExpired(): bool
    {
        return is_null($this->ended_at) && $this->expire_at <= now();
    }

    public function isCompleted(): bool
    {
        return !is_null($this->ended_at);
    }

    // -----------------------------
    // Accessor Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateGame(Game $game): bool
    {
        $this->game()->associate($game);
        $this->energy_cost = $game->energy_cost;
        return $this->save();
    }

    public function associateCustomer(GameUser $customer): bool
    {
        $this->customer()->associate($customer);
        return $this->save();
    }

    public function start(): bool
    {
        $this->started_at = now();
        $this->expire_at = now()->addHour(); // 1 hour expiry
        return $this->save();
    }

    public function end(string $outcome, int $coinsEarned = 0): bool
    {
        $this->ended_at = now();
        $this->outcome = $outcome;
        $this->coins_earned = $outcome === 'win' ? $coinsEarned : 0;
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}

