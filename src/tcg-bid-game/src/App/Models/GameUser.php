<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\HasMany;

// Traits
use App\Models\Traits\ObjectIDTrait;

class GameUser extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_users';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_users';
    }

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null, // Reference to main Customer model
        // Currency settings (balances calculated from transactions)
        'max_energy' => 20,
        'energy_recovery_interval_hours' => 1, // Energy recovers every hour
        'energy_recovery_amount' => 1, // Amount of energy recovered per interval
        'last_energy_recovery_check' => null,
        // Settings
        'settings' => [
            'language' => 'en',
            'sound_enabled' => true,
            'music_enabled' => true,
            'notifications_enabled' => true,
            'vibration_enabled' => false,
        ],
        // Onboarding
        'onboarding_completed' => false,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Scope Begins
    // -----------------------------

    public function scopeByCustomerId(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    // -----------------------------
    // Scope Ends
    // -----------------------------

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'customer_id');
    }

    public function gameSessions(): HasMany
    {
        return $this->hasMany(GameSession::class, 'customer_id');
    }

    public function adViews(): HasMany
    {
        return $this->hasMany(AdView::class, 'customer_id');
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Accessor Begins
    // -----------------------------

    public function getCurrencyAttribute(): array
    {
        return [
            'coins' => $this->getCoinsBalance(),
            'energy' => $this->getEnergyBalance(),
            'max_energy' => $this->max_energy,
            'last_energy_refill' => $this->last_energy_recovery_check,
        ];
    }

    // -----------------------------
    // Accessor Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    /**
     * Calculate coins balance from transaction logs
     */
    public function getCoinsBalance(): int
    {
        return (int) $this->transactions()
            ->where('currency_type', 'coins')
            ->sum('amount');
    }

    /**
     * Calculate energy balance from transaction logs
     */
    public function getEnergyBalance(): int
    {
        $currentBalance = (int) $this->transactions()
            ->where('currency_type', 'energy')
            ->sum('amount');

        // Cap at max_energy
        return min($currentBalance, $this->max_energy);
    }

    /**
     * Add coins via transaction
     */
    public function addCoins(int $amount, string $transactionType, ?string $referenceId = null, ?string $referenceType = null, ?array $description = null): Transaction
    {
        $currentBalance = $this->getCoinsBalance();
        $newBalance = $currentBalance + $amount;

        $transaction = Transaction::create([
            'customer_id' => $this->_id,
            'transaction_type' => $transactionType,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'currency_type' => 'coins',
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'description' => $description ?? [
                'zh' => "獲得{$amount}金幣",
                'en' => "Earned {$amount} coins",
                'cn' => "获得{$amount}金币",
            ],
        ]);
        $transaction->associateCustomer($this);

        return $transaction;
    }

    /**
     * Deduct coins via transaction
     */
    public function deductCoins(int $amount, string $transactionType, ?string $referenceId = null, ?string $referenceType = null, ?array $description = null): ?Transaction
    {
        $currentBalance = $this->getCoinsBalance();

        if ($currentBalance < $amount) {
            return null; // Insufficient balance
        }

        $newBalance = $currentBalance - $amount;

        $transaction = Transaction::create([
            'customer_id' => $this->_id,
            'transaction_type' => $transactionType,
            'amount' => -$amount, // Negative for deduction
            'balance_after' => $newBalance,
            'currency_type' => 'coins',
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'description' => $description ?? [
                'zh' => "花費{$amount}金幣",
                'en' => "Spent {$amount} coins",
                'cn' => "花费{$amount}金币",
            ],
        ]);
        $transaction->associateCustomer($this);

        return $transaction;
    }

    public function hasEnoughCoins(int $amount): bool
    {
        return $this->getCoinsBalance() >= $amount;
    }

    /**
     * Add energy via transaction (capped at max_energy)
     */
    public function addEnergy(int $amount, string $transactionType, ?string $referenceId = null, ?string $referenceType = null, ?array $description = null): Transaction
    {
        $currentBalance = $this->getEnergyBalance();
        $newBalance = min($currentBalance + $amount, $this->max_energy);
        $actualAmount = $newBalance - $currentBalance; // Actual amount added (may be less if at max)

        $transaction = Transaction::create([
            'customer_id' => $this->_id,
            'transaction_type' => $transactionType,
            'amount' => $actualAmount,
            'balance_after' => $newBalance,
            'currency_type' => 'energy',
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'description' => $description ?? [
                'zh' => "獲得{$actualAmount}能量",
                'en' => "Earned {$actualAmount} energy",
                'cn' => "获得{$actualAmount}能量",
            ],
        ]);
        $transaction->associateCustomer($this);

        return $transaction;
    }

    /**
     * Deduct energy via transaction
     */
    public function deductEnergy(int $amount, string $transactionType, ?string $referenceId = null, ?string $referenceType = null, ?array $description = null): ?Transaction
    {
        $currentBalance = $this->getEnergyBalance();

        if ($currentBalance < $amount) {
            return null; // Insufficient energy
        }

        $newBalance = $currentBalance - $amount;

        $transaction = Transaction::create([
            'customer_id' => $this->_id,
            'transaction_type' => $transactionType,
            'amount' => -$amount, // Negative for deduction
            'balance_after' => $newBalance,
            'currency_type' => 'energy',
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'description' => $description ?? [
                'zh' => "消耗{$amount}能量",
                'en' => "Spent {$amount} energy",
                'cn' => "消耗{$amount}能量",
            ],
        ]);
        $transaction->associateCustomer($this);

        return $transaction;
    }

    public function hasEnoughEnergy(int $amount): bool
    {
        return $this->getEnergyBalance() >= $amount;
    }

    /**
     * Check and recover energy based on time interval
     * This should be called periodically (e.g., via scheduled task or on user request)
     */
    public function checkAndRecoverEnergy(): int
    {
        $now = now();
        $lastCheck = $this->last_energy_recovery_check ? \Carbon\Carbon::parse($this->last_energy_recovery_check) : null;

        // If never checked, set initial check time
        if (!$lastCheck) {
            $this->last_energy_recovery_check = $now;
            $this->save();
            return 0;
        }

        // Calculate hours passed
        $hoursPassed = $now->diffInHours($lastCheck);

        if ($hoursPassed < $this->energy_recovery_interval_hours) {
            return 0; // Not enough time has passed
        }

        // Calculate how many recovery cycles have passed
        $recoveryCycles = (int) floor($hoursPassed / $this->energy_recovery_interval_hours);
        $totalRecovery = $recoveryCycles * $this->energy_recovery_amount;

        // Update last check time
        $this->last_energy_recovery_check = $now;
        $this->save();

        // Add energy via transaction
        if ($totalRecovery > 0) {
            $this->addEnergy($totalRecovery, 'energy_recovery', null, null, [
                'zh' => "自然恢復{$totalRecovery}能量",
                'en' => "Naturally recovered {$totalRecovery} energy",
                'cn' => "自然恢复{$totalRecovery}能量",
            ]);
        }

        return $totalRecovery;
    }

    public function updateSettings(array $settings): bool
    {
        $currentSettings = $this->settings ?? [
            'language' => 'en',
            'sound_enabled' => true,
            'music_enabled' => true,
            'notifications_enabled' => true,
            'vibration_enabled' => false,
        ];
        $this->settings = array_merge($currentSettings, $settings);
        return $this->save();
    }

    public function completeOnboarding(): bool
    {
        $this->onboarding_completed = true;
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
