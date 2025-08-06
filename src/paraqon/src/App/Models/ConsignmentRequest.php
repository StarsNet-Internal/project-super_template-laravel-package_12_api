<?php

namespace Starsnet\Project\Paraqon\App\Models;

// Default
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\EmbedsMany;

// Traits
use App\Models\Traits\ObjectIDTrait;
use App\Models\Traits\StatusFieldTrait;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use App\Models\Account;
use App\Models\Customer;

class ConsignmentRequest extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    // Connection
    protected $connection = 'mongodb';
    protected $collection = 'consignment_requests';

    // Attributes
    protected $attributes = [
        // Relationships
        'requested_by_customer_id' => null,
        'approved_by_account_id' => null,

        // Default
        'title' => null,
        'gender' => null,
        'first_name' => null,
        'middle_name' => null,
        'last_name' => null,
        'date_of_birth' => null,
        'nationality' => null,
        'hkid' => null,
        'passport' => null,
        'tin' => null,
        'other_phones' => [],
        'area_code' => null,
        'home_phone' => null,
        'email' => null,
        'address_line_1' => null,
        'address_line_2' => null,
        'building' => null,
        'state' => null,
        'city' => null,
        'country' => null,
        'postal_code' => null,
        'remarks' => null,
        'items' => [],
        'requested_items_qty' => 0,
        'approved_items_qty' => 0,
        'status' => Status::ACTIVE->value,
        'reply_status' => ReplyStatus::PENDING->value,

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

    public function items(): EmbedsMany
    {
        return $this->embedsMany(
            ConsignmentRequestItem::class,
            'items'
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

    public function associateRequestedAccount(Account $account): bool
    {
        $this->requestedAccount()->associate($account);
        return $this->save();
    }

    public function associateApprovedAccount(Account $account): bool
    {
        $this->approvedAccount()->associate($account);
        return $this->save();
    }

    public function updateReplyStatus(string $status): bool
    {
        $this->reply_status = $status;
        return $this->save();
    }

    // -----------------------------
    // Action Ends
    // -----------------------------
}
