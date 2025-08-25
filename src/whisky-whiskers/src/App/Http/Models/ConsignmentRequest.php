<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Models;

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

class ConsignmentRequest extends Model
{
    use ObjectIDTrait,
        StatusFieldTrait;

    protected $connection = 'mongodb';
    protected $collection = 'consignment_requests';

    protected $attributes = [
        // Relationships
        'requested_by_account_id' => null,
        'approved_by_account_id' => null,
        // Default
        'requester_name' => null,
        'email' => null,
        'area_code' => null,
        'phone' => null,
        'shipping_address' => null,
        'items' => [],
        'requested_items_qty' => 0,
        'approved_items_qty' => 0,
        'status' => Status::ACTIVE,
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

    public function requestedAccount(): BelongsTo
    {
        return $this->belongsTo(
            Account::class,
            'requested_by_account_id'
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
