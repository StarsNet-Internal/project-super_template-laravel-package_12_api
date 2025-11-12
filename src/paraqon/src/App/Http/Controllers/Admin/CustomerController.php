<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\LoginType;
use App\Enums\Status;

// Models
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;

class CustomerController extends Controller
{
    public function getAllCustomers(): Collection
    {
        return Customer::with([
            'account',
            'account.user'
        ])
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('type', '!=', LoginType::TEMP->value)
                        ->where('is_deleted', false);
                });
            })
            ->get();
    }

    public function getCustomerDetails(Request $request): Customer
    {
        /** @var ?Customer $customer */
        $customer = Customer::with([
            'account',
            'account.user',
            'account.notificationSetting'
        ])
            ->find($request->route('id'));
        if (is_null($customer)) abort(404, 'Customer not found');

        return $customer;
    }

    public function getAllOwnedProducts(Request $request): Collection
    {
        /** @var Collection $products */
        $products = Product::where('status', '!=', Status::DELETED->value)
            ->where('owned_by_customer_id', $request->route('customer_id'))
            ->get();

        foreach ($products as $product) {
            $product->product_variant_id = optional($product->variants()->latest()->first())->_id;
        }

        return $products;
    }

    public function getAllOwnedAuctionLots(Request $request): Collection
    {
        return AuctionLot::with([
            'product',
            'productVariant',
            'store',
            'latestBidCustomer',
            'winningBidCustomer'
        ])
            ->where('owned_by_customer_id', $request->route('customer_id'))
            ->where('status', '!=', Status::DELETED->value)
            ->get()
            ->each(function ($auctionLot) {
                $auctionLot->setAttribute(
                    'current_bid',
                    $auctionLot->getCurrentBidPrice()
                );
            });
    }

    public function getAllBids(Request $request): Collection
    {
        return Bid::with(['store', 'product', 'auctionLot'])
            ->where('customer_id', $request->route('customer_id'))
            ->get();
    }

    public function hideBid(Request $request): array
    {
        Bid::where('_id', $request->route('bid_id'))->update(['is_hidden' => true]);
        return ['message' => 'Bid updated is_hidden as true'];
    }

    public function loginAsCustomer(Request $request): array
    {
        // Extract attributes from $request
        $loginType = strtoupper($request->input('type', LoginType::EMAIL->value));

        // Attempt to find User via Account Model
        $userID = null;
        switch ($loginType) {
            case LoginType::EMAIL->value:
            case LoginType::TEMP->value:
                $account = Account::where('email', $request->email)->first();
                $userID = optional($account)->user_id;
                break;
            case LoginType::PHONE->value:
                $account = Account::where('area_code', $request->area_code)
                    ->where('phone', $request->phone)
                    ->first();
                $userID = optional($account)->user_id;
                break;
            default:
                break;
        }

        /** @var ?User $user */
        $user = User::find($userID);
        if (is_null($user)) abort(404, 'User not found.');

        // Create token
        $accessToken = $user->createToken('customer')->accessToken;

        return [
            'token' => $accessToken,
            'user' => $user
        ];
    }
}
