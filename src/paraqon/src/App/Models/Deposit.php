<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\EmbedsMany;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Enums
use App\Enums\CollectionName;
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use App\Models\Account;
use App\Models\Customer;

class Deposit extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = CollectionName::DEPOSIT->value;

    // Attributes
    protected $attributes = [
        // Relationships
        'requested_by_customer_id' => null,
        'auction_registration_request_id' => null,
        'approved_by_account_id' => null,

        // Default
        'payment_method' => 'OFFLINE',
        'amount' => null,
        'amount_captured' => null,
        'amount_refunded' => null,
        'currency' => null,
        'online' => [
            'payment_intent_id' => null,
            'client_secret' => null,
            'api_response' => null
        ],
        'offline' => [
            'image' => null,
            'uploaded_at' => null,
            'api_response' => null
        ],
        'payment_information' => [
            'currency' => 'HKD',
            'conversion_rate' => '100'
        ],
        'current_deposit_status' => null,
        'deposit_statuses' => [],
        'status' => Status::ACTIVE->value,
        'reply_status' => ReplyStatus::PENDING->value,
        'permission_type' => null,
        'remarks' => null,

        // Timestamps
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

    public function auctionRegistrationRequest(): BelongsTo
    {
        return $this->belongsTo(
            AuctionRegistrationRequest::class,
            'auction_registration_request_id'
        );
    }

    public function depositStatuses(): EmbedsMany
    {
        $localKey = 'deposit_statuses';

        return $this->embedsMany(
            DepositStatus::class,
            $localKey
        );
    }

    // -----------------------------
    // Relationship Ends
    // -----------------------------

    // -----------------------------
    // Accessor Begins
    // -----------------------------

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

    public function updateStatus(string $slug, ?string $remarks = ""): DepositStatus
    {
        $slug = Str::slug($slug);

        // Update status
        $attributes = [
            'slug' => $slug,
            'remarks' => $remarks
        ];
        /** @var DepositStatus $status */
        $status = $this->depositStatuses()->create($attributes);

        // Update current_status
        $this->updateCurrentStatus($slug);

        return $status;
    }

    public function updateCurrentStatus(string $slug): bool
    {
        $slug = Str::slug($slug);
        $this->current_deposit_status = $slug;
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
