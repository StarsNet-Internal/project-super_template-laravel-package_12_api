<?php
// starsnet/src/paraqon/src/App/Services/TemporaryUserCleanupService.php

namespace Starsnet\Project\Paraqon\App\Services;

use App\Models\User;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ShoppingCartItem;
use App\Models\Order;
use App\Models\NotificationSetting;
use App\Models\VerificationCode;
use Carbon\Carbon;

use Illuminate\Support\Facades\Log;

// Package Models
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\Deposit;
use Starsnet\Project\Paraqon\App\Models\Notification;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;

class TemporaryUserCleanupService
{
    private const CHUNK_SIZE = 100;

    public function __construct(?Carbon $cutOffDate = null)
    {
        $this->cutoffDate = $cutOffDate ?? Carbon::now()->subDays(2);
    }

    public function cleanup()
    {
        // Get TEMP user, account, customer
        Log::info('Getting Temporary Users');
        $tempUserIDs = $this->getTemporaryUsers();
        Log::info('Getting Temporary Accounts');
        $tempAccountIDs = $this->getTemporaryAccounts($tempUserIDs);
        Log::info('Getting Temporary Customers');
        $tempCustomerIDs = $this->getTemporaryCustomers($tempAccountIDs);

        // Get TEMP users who have items in shopping_cart_items, orders, auction_registration_requests, bids, deposits, or watchlist_items
        Log::info('Getting Shopping Cart Item Customer IDs');
        $shoppingCartItemCustomerIDs = $this->getCustomerIDsWhoHaveShoppingCartItems()->toArray();
        Log::info('Getting Order Customer IDs');
        $orderCustomerIDs = $this->getCustomerIDsWhoHaveOrders()->toArray();

        $auctionRegistrationRequestCustomerIDs = $this->getCustomerIDsWhoHaveAuctionRegistrationRequests()->toArray();
        Log::info('Getting Bid Customer IDs');
        $bidCustomerIDs = $this->getCustomerIDsWhoHaveBids()->toArray();
        $depositCustomerIDs = $this->getCustomerIDsWhoHaveDeposits()->toArray();
        Log::info('Getting Watchlist Item Customer IDs');
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
        Log::info('Getting Keep Account IDs');
        $keepAccountIDs = [];
        if (!empty($keepCustomerIDs)) {
            // MongoDB can handle large arrays, but chunking helps with performance
            foreach (array_chunk($keepCustomerIDs, self::CHUNK_SIZE) as $chunk) {
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
        Log::info('Getting Keep User IDs');
        $keepUserIDs = [];
        if (!empty($keepAccountIDs)) {
            // Account is MongoDB, but we're querying for user_id which will be used in MySQL
            // Chunking here helps when we later query User (MySQL)
            foreach (array_chunk($keepAccountIDs, self::CHUNK_SIZE) as $chunk) {
                $userIDs = Account::whereIn('id', $chunk)
                    ->select('user_id')
                    ->get()
                    ->pluck('user_id')
                    ->toArray();
                $keepUserIDs = array_merge($keepUserIDs, $userIDs);
            }
        }

        // Update ids need to be deleted
        Log::info('Updating IDs to be deleted');
        $tempUserIDs = array_diff($tempUserIDs, $keepUserIDs);
        Log::info('Getting Temporary Accounts');
        $tempAccountIDs = $this->getTemporaryAccounts($tempUserIDs);
        Log::info('Getting Temporary Customers');
        $tempCustomerIDs = $this->getTemporaryCustomers($tempAccountIDs);

        // Delete Records
        // Category (MongoDB) - no placeholder limit, but chunking for performance
        Log::info('Deleting Category');
        $deletedCategoryCount = 0;
        if (!empty($tempCustomerIDs)) {
            foreach (array_chunk($tempCustomerIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedCategoryCount += Category::where('slug', 'personal-customer-group')
                    ->whereIn('item_ids', $chunk)
                    ->delete();
            }
        }
        Log::info('Deleted Category');

        // User (MySQL) - MUST chunk to avoid placeholder limit
        $deletedUserCount = 0;
        Log::info('Deleting User');
        if (!empty($tempUserIDs)) {
            foreach (array_chunk($tempUserIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedUserCount += User::whereIn('id', $chunk)->delete();
            }
        }
        Log::info('Deleted User');
        // Account (MongoDB) - no placeholder limit, but chunking for performance
        $deletedAccountCount = 0;
        Log::info('Deleting Account');
        if (!empty($tempAccountIDs)) {
            foreach (array_chunk($tempAccountIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedAccountCount += Account::whereIn('id', $chunk)->delete();
            }
        }
        Log::info('Deleted Account');
        // Customer (MongoDB) - no placeholder limit, but chunking for performance
        $deletedCustomerCount = 0;
        Log::info('Deleting Customer');
        if (!empty($tempCustomerIDs)) {
            foreach (array_chunk($tempCustomerIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedCustomerCount += Customer::whereIn('id', $chunk)->delete();
            }
        }
        Log::info('Deleted Customer');
        // Notification (MongoDB) - key is account_id
        $deletedNotificationCount = 0;
        Log::info('Deleting Notification');
        if (!empty($tempAccountIDs)) {
            foreach (array_chunk($tempAccountIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedNotificationCount += Notification::whereIn('account_id', $chunk)->delete();
            }
        }
        Log::info('Deleted Notification');
        // NotificationSetting (MongoDB) - key is account_id
        $deletedNotificationSettingCount = 0;
        Log::info('Deleting NotificationSetting');
        if (!empty($tempAccountIDs)) {
            foreach (array_chunk($tempAccountIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedNotificationSettingCount += NotificationSetting::whereIn('account_id', $chunk)->delete();
            }
        }
        Log::info('Deleted NotificationSetting');
        // VerificationCode (MongoDB) - key is user_id
        $deletedVerificationCodeCount = 0;
        Log::info('Deleting VerificationCode');
        if (!empty($tempUserIDs)) {
            foreach (array_chunk($tempUserIDs, self::CHUNK_SIZE) as $chunk) {
                $deletedVerificationCodeCount += VerificationCode::whereIn('user_id', $chunk)->delete();
            }
        }
        Log::info('Deleted VerificationCode');

        Log::info('Deleted User Count: ' . $deletedUserCount);
        Log::info('Deleted Account Count: ' . $deletedAccountCount);
        Log::info('Deleted Customer Count: ' . $deletedCustomerCount);
        Log::info('Deleted Notification Count: ' . $deletedNotificationCount);
        Log::info('Deleted NotificationSetting Count: ' . $deletedNotificationSettingCount);
        Log::info('Deleted VerificationCode Count: ' . $deletedVerificationCodeCount);

        return [
            'user_deleted_count' => $deletedUserCount,
            'account_deleted_count' => $deletedAccountCount,
            'customer_deleted_count' => $deletedCustomerCount,
            'category_deleted_count' => $deletedCategoryCount,
            'notification_deleted_count' => $deletedNotificationCount,
            'notification_setting_deleted_count' => $deletedNotificationSettingCount,
            'verification_code_deleted_count' => $deletedVerificationCodeCount,
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
        foreach (array_chunk($tempUserIDs, self::CHUNK_SIZE) as $chunk) {
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
        foreach (array_chunk($tempAccountIDs, self::CHUNK_SIZE) as $chunk) {
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
