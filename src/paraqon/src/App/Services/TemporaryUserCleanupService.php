<?php
// starsnet/src/paraqon/src/App/Services/TemporaryUserCleanupService.php

namespace Starsnet\Project\Paraqon\App\Services;

use App\Models\User;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ShoppingCartItem;
use App\Models\Order;
use Carbon\Carbon;

use Illuminate\Support\Facades\Log;

// Package Models
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\Deposit;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;

class TemporaryUserCleanupService
{
    public function __construct(?Carbon $cutOffDate = null)
    {
        $this->cutoffDate = $cutOffDate ?? Carbon::now()->subDays(2);
    }

    public function cleanup()
    {
        // Get TEMP user, account, customer
        $tempUserIDs = $this->getTemporaryUsers();
        $tempAccountIDs = $this->getTemporaryAccounts($tempUserIDs);
        $tempCustomerIDs = $this->getTemporaryCustomers($tempAccountIDs);

        // Get TEMP users who have items in shopping_cart_items, orders, auction_registration_requests, bids, deposits, or watchlist_items
        $shoppingCartItemCustomerIDs = $this->getCustomerIDsWhoHaveShoppingCartItems()->toArray();
        $orderCustomerIDs = $this->getCustomerIDsWhoHaveOrders()->toArray();

        $auctionRegistrationRequestCustomerIDs = $this->getCustomerIDsWhoHaveAuctionRegistrationRequests()->toArray();
        $bidCustomerIDs = $this->getCustomerIDsWhoHaveBids()->toArray();
        $depositCustomerIDs = $this->getCustomerIDsWhoHaveDeposits()->toArray();
        $watchlistItemCustomerIDs = $this->getCustomerIDsWhoHaveWatchlistItems()->toArray();
        $keepCustomerIDs = array_unique(array_merge(
            $shoppingCartItemCustomerIDs,
            $orderCustomerIDs,
            $auctionRegistrationRequestCustomerIDs,
            $bidCustomerIDs,
            $depositCustomerIDs,
            $watchlistItemCustomerIDs
        ));

        // Get keepAccountIDs (MongoDB - no placeholder limit, but chunking for performance)
        $keepAccountIDs = [];
        if (!empty($keepCustomerIDs)) {
            // MongoDB can handle large arrays, but chunking helps with performance
            foreach (array_chunk($keepCustomerIDs, 5000) as $chunk) {
                $keepAccountIDs = array_merge(
                    $keepAccountIDs,
                    Customer::whereIn('id', $chunk)
                        ->select('account_id')
                        ->get()
                        ->pluck('account_id')
                        ->toArray()
                );
            }
        }

        // Get keepUserIDs - chunk for MySQL placeholder limit
        $keepUserIDs = [];
        if (!empty($keepAccountIDs)) {
            // Account is MongoDB, but we're querying for user_id which will be used in MySQL
            // Chunking here helps when we later query User (MySQL)
            foreach (array_chunk($keepAccountIDs, 5000) as $chunk) {
                $userIDs = Account::whereIn('id', $chunk)
                    ->select('user_id')
                    ->get()
                    ->pluck('user_id')
                    ->toArray();
                $keepUserIDs = array_merge($keepUserIDs, $userIDs);
            }
        }

        // Update ids need to be deleted
        $tempUserIDs = array_diff($tempUserIDs, $keepUserIDs);
        $tempAccountIDs = $this->getTemporaryAccounts($tempUserIDs);
        $tempCustomerIDs = $this->getTemporaryCustomers($tempAccountIDs);

        // Delete Records
        // Category (MongoDB) - no placeholder limit, but chunking for performance
        $deletedCategoryCount = 0;
        if (!empty($tempCustomerIDs)) {
            foreach (array_chunk($tempCustomerIDs, 5000) as $chunk) {
                $deletedCategoryCount += Category::where('slug', 'personal-customer-group')
                    ->whereIn('item_ids', $chunk)
                    ->delete();
            }
        }

        // User (MySQL) - MUST chunk to avoid placeholder limit
        $deletedUserCount = 0;
        if (!empty($tempUserIDs)) {
            foreach (array_chunk($tempUserIDs, 1000) as $chunk) {
                $deletedUserCount += User::whereIn('id', $chunk)->delete();
            }
        }

        // Account (MongoDB) - no placeholder limit, but chunking for performance
        $deletedAccountCount = 0;
        if (!empty($tempAccountIDs)) {
            foreach (array_chunk($tempAccountIDs, 5000) as $chunk) {
                $deletedAccountCount += Account::whereIn('id', $chunk)->delete();
            }
        }

        // Customer (MongoDB) - no placeholder limit, but chunking for performance
        $deletedCustomerCount = 0;
        if (!empty($tempCustomerIDs)) {
            foreach (array_chunk($tempCustomerIDs, 5000) as $chunk) {
                $deletedCustomerCount += Customer::whereIn('id', $chunk)->delete();
            }
        }

        Log::info('Deleted User Count: ' . $deletedUserCount);

        return [
            'user_deleted_count' => $deletedUserCount,
            'account_deleted_count' => $deletedAccountCount,
            'customer_deleted_count' => $deletedCustomerCount,
            'category_deleted_count' => $deletedCategoryCount,
        ];
    }

    public function getTemporaryUsers()
    {
        $tempUserIDs = User::where('type', 'TEMP')
            ->where('created_at', '<=', $this->cutoffDate)
            ->select('id')
            ->get()
            ->pluck('id')
            ->all();

        return $tempUserIDs;
    }

    public function getTemporaryAccounts(array $tempUserIDs)
    {
        $tempAccountIDs = [];
        if (empty($tempUserIDs)) {
            return $tempAccountIDs;
        }

        // Account is MongoDB, but we're querying by user_id from MySQL
        // Chunking helps with performance, but not required for placeholder limit
        foreach (array_chunk($tempUserIDs, 5000) as $chunk) {
            $tempAccountIDs = array_merge(
                $tempAccountIDs,
                Account::whereIn('user_id', $chunk)
                    ->select('_id')
                    ->get()
                    ->pluck('id')
                    ->all()
            );
        }

        return $tempAccountIDs;
    }

    public function getTemporaryCustomers(array $tempAccountIDs)
    {
        $tempCustomerIDs = [];
        if (empty($tempAccountIDs)) {
            return $tempCustomerIDs;
        }

        // Customer is MongoDB - no placeholder limit, but chunking for performance
        foreach (array_chunk($tempAccountIDs, 5000) as $chunk) {
            $tempCustomerIDs = array_merge(
                $tempCustomerIDs,
                Customer::whereIn('account_id', $chunk)
                    ->select('_id')
                    ->get()
                    ->pluck('id')
                    ->all()
            );
        }

        return $tempCustomerIDs;
    }

    public function getCustomerIDsWhoHaveShoppingCartItems()
    {
        return ShoppingCartItem::pluck('customer_id')->unique()->values();
    }

    public function getCustomerIDsWhoHaveOrders()
    {
        return Order::pluck('customer_id')->unique()->values();
    }

    public function getCustomerIDsWhoHaveAuctionRegistrationRequests()
    {
        return AuctionRegistrationRequest::pluck('requested_by_customer_id')->unique()->filter()->values();
    }

    public function getCustomerIDsWhoHaveBids()
    {
        return Bid::pluck('customer_id')->unique()->filter()->values();
    }

    public function getCustomerIDsWhoHaveWatchlistItems()
    {
        return WatchlistItem::pluck('customer_id')->unique()->filter()->values();
    }

    public function getCustomerIDsWhoHaveDeposits()
    {
        return Deposit::pluck('requested_by_customer_id')->unique()->filter()->values();
    }
}
