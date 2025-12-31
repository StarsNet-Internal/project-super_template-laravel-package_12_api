<?php

namespace Starsnet\Project\TcgBidGame\App\Models;

// Default
use Illuminate\Database\Eloquent\Builder;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;

class Order extends Model
{
    use ObjectIDTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'game_orders';
    
    /**
     * Get the table name for the model (MongoDB uses this for collection name)
     *
     * @return string
     */
    public function getTable()
    {
        return 'game_orders';
    }

    // Attributes
    protected $attributes = [
        // Relationships
        'customer_id' => null,
        // Default
        'order_number' => null,
        'status' => 'processing', // processing, shipped, completed, cancelled
        'total_amount' => 0,
        'item' => [
            'product_id' => null,
            'product_name' => [
                'zh' => null,
                'en' => null,
                'cn' => null,
            ],
            'unit_price' => 0,
        ],
        'shipped_at' => null,
        'completed_at' => null,
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Scope Begins
    // -----------------------------

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeByStatuses(Builder $query, array $statuses): Builder
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeByCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class,
            'item.product_id'
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

    public function updateStatus(string $status): bool
    {
        $this->status = $status;
        
        if ($status === 'shipped' && !$this->shipped_at) {
            $this->shipped_at = now();
        }
        
        if ($status === 'completed' && !$this->completed_at) {
            $this->completed_at = now();
        }
        
        return $this->save();
    }

    public function associateCustomer(GameUser $customer): bool
    {
        $this->customer()->associate($customer);
        return $this->save();
    }

    public function associateProduct(Product $product): bool
    {
        $this->item = [
            'product_id' => $product->_id,
            'product_name' => $product->name,
            'unit_price' => $product->price,
        ];
        return $this->save();
    }

    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD';
        $year = now()->format('Y');
        $sequence = self::where('order_number', 'like', "{$prefix}-{$year}-%")
            ->count() + 1;
        
        return sprintf('%s-%s-%03d', $prefix, $year, $sequence);
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}

