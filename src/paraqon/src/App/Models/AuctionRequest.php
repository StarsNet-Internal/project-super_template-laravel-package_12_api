<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;

class AuctionRequest extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'auction_requests';

    // Attributes
    protected $attributes = [
        // Relationships
        'requested_by_customer_id' => null,
        'approved_by_account_id' => null,
        'product_id' => null,
        'product_variant_id' => null,
        'store_id' => null,

        // Default
        'starting_bid' => 0,
        'reserve_price' => 0,

        'status' => Status::ACTIVE->value,
        'reply_status' => ReplyStatus::PENDING->value,
        'remarks' => null,

        // Booleans
        'is_in_auction' => false,

        // Timestamps
        'deleted_at' => null
    ];

    protected $guarded = [];
    protected $appends = ['_id'];
    protected $hidden = ['id'];

    // -----------------------------
    // Relationship Begins
    // -----------------------------

    public function requestedCustomer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class,
            'requested_by_customer_id'
        );
    }

    public function approvedAccount(): BelongsTo
    {
        return $this->belongsTo(
            Account::class,
            'approved_by_account_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class,
        );
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
        );
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(
            Store::class,
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Accessor Begins
    // -----------------------------

    public function getProductInfoAttribute(): array
    {
        $account = $this->requestedAccount()->first();

        return [
            'user_id' => optional($account)->user->id,
            'account_id' => optional($account)->_id,
            'username' => optional($account)->username,
            'avatar' => optional($account)->avatar
        ];
    }

    public function getRequestedAccountAttribute(): array
    {
        $account = $this->requestedAccount()->first();

        return [
            'user_id' => optional($account)->user->id,
            'account_id' => optional($account)->_id,
            'username' => optional($account)->username,
            'avatar' => optional($account)->avatar
        ];
    }

    public function getApprovedAccountAttribute(): array
    {
        $account = $this->approvedAccount()->first();

        return [
            'user_id' => optional($account)->user->id,
            'account_id' => optional($account)->_id,
            'username' => optional($account)->username,
            'avatar' => optional($account)->avatar
        ];
    }

    // -----------------------------
    // Accessor Ends
    // -----------------------------

    // -----------------------------
    // Action Begins
    // -----------------------------

    public function associateRequestedCustomer(Customer $customer): bool
    {
        $this->requestedCustomer()->associate($customer);
        return $this->save();
    }

    public function associateApprovedAccount(Account $account): bool
    {
        $this->approvedAccount()->associate($account);
        return $this->save();
    }

    public function associateStore(Store $store): bool
    {
        $this->store()->associate($store);
        return $this->save();
    }

    public function updateReplyStatus(string $status): bool
    {
        $this->reply_status = $status;
        return $this->save();
    }

    public function getProduct(): Product
    {
        return $this->product()->first();
    }

    public function getProductVariant(): ProductVariant
    {
        return $this->productVariant()->first();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
