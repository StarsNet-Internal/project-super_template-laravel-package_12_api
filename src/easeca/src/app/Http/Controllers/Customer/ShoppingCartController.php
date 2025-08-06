<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Customer\ShoppingCartController as CustomerShoppingCartController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Models
use App\Models\Alias;
use App\Models\ProductVariant;
use App\Models\Store;

class ShoppingCartController extends CustomerShoppingCartController
{
    /** @var Store $store */
    protected $store;

    public function getStoreByAccount(Request $request)
    {
        $account = $this->account();
        if ($account['store_id'] != null) {
            $this->store = Store::find($request->route('store_id'))
                ?? Store::find(Alias::getValue($account['store_id']));
        } else {
            $this->store = Store::find($request->route('store_id'))
                ?? Store::find(Alias::getValue($request->route('store_id')));
        }
    }

    public function addToCart(Request $request): array
    {
        $this->getStoreByAccount($request);
        return parent::addToCart($request);
    }

    public function getAll(Request $request): array
    {
        $this->getStoreByAccount($request);

        $data = json_decode(json_encode(parent::getAll($request)), true)['original'];

        $data['cart_items'] = array_map(function ($item) {
            $variant = ProductVariant::find($item['product_variant_id']);
            $item['subtotal_point'] = number_format($variant->cost, 2, '.', '');
            return $item;
        }, $data['cart_items']);

        try {
            $url = 'https://timetable.easeca.tinkleex.com/customer/schedules/cut-off?store_id=' . $this->store->_id;
            $response = Http::get($url);
            $hour = json_decode($response->getBody()->getContents(), true);
            $data['calculations']['currency'] = $hour['hour'];
        } catch (\Throwable $th) {
            $data['calculations']['currency'] = '17';
        }

        return $data;
    }

    public function clearCartByAccount(Request $request): array
    {
        $this->getStoreByAccount($request);
        return parent::clearCart();
    }
}
