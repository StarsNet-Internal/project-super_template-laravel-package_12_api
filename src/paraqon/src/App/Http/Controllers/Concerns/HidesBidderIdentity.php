<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Auth;

trait HidesBidderIdentity
{
    private function viewerCustomerId(): ?string
    {
        try {
            $user = Auth::guard('api')->user();
        } catch (\Throwable $e) {
            return null;
        }

        $customer = optional(optional($user)->account)->customer;
        if (!$customer instanceof Customer) {
            return null;
        }

        return (string) $customer->id;
    }

    private function bidderKey($auctionId, $customerId): ?string
    {
        if ($auctionId === null || $customerId === null || $customerId === '') {
            return null;
        }

        return substr(hash_hmac(
            'sha256',
            (string) $auctionId . '|' . (string) $customerId,
            (string) config('app.key')
        ), 0, 24);
    }

    private function isOwnBidder($customerId, ?string $viewerId): bool
    {
        if ($viewerId === null || $customerId === null || $customerId === '') {
            return false;
        }

        return (string) $customerId == $viewerId;
    }

    private function publicBidderRow($record, $customerId, $auctionId, ?string $viewerId): array
    {
        $row = $record instanceof Arrayable ? $record->toArray() : (array) $record;
        unset(
            $row['customer_id'],
            $row['winning_bid_customer_id'],
            $row['username'],
            $row['avatar'],
            $row['account'],
            $row['customer']
        );
        $row['bidder_key'] = $this->bidderKey($auctionId, $customerId);
        $row['is_own'] = $this->isOwnBidder($customerId, $viewerId);

        return $row;
    }
}
