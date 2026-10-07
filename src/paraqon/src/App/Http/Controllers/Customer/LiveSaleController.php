<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Starsnet\Project\Paraqon\App\LiveSale\LiveSaleService;

class LiveSaleController extends Controller
{
    public function __construct(private LiveSaleService $sales) {}

    public function show(Request $request): array
    {
        return $this->sales->snapshot(
            (string) $request->route('store_id'),
            false,
            $this->viewerCustomerId()
        );
    }

    public function command(Request $request): array
    {
        $command = (string) $request->input('command');
        if ($command !== 'submit_online_bid') {
            abort(422, 'Unknown live sale command');
        }

        return $this->sales->run(
            (string) $request->route('store_id'),
            $command,
            $request->except(['command', 'customer_id', 'paddle_id']),
            $this->viewerCustomerId(),
            false,
            $this->viewerCustomerId()
        );
    }

    private function viewerCustomerId(): ?string
    {
        $user = Auth::guard('api')->user();
        $customer = optional(optional($user)->account)->customer;
        if (!$customer) {
            return null;
        }
        return (string) $customer->_id;
    }
}
