<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Starsnet\Project\Paraqon\App\LiveSale\LiveSaleService;

class LiveSaleController extends Controller
{
    public function __construct(private LiveSaleService $sales) {}

    public function show(Request $request): array
    {
        return $this->sales->snapshot((string) $request->route('store_id'), true, null);
    }

    public function command(Request $request): array
    {
        $command = (string) $request->input('command');
        $allowed = [
            'start_sale',
            'prepare_lot',
            'open_lot',
            'set_asking_price',
            'set_increment',
            'accept_bid',
            'accept_pending',
            'reject_pending',
            'warn',
            'sell',
            'pass',
            'undo_latest_bid',
            'reopen',
            'end_sale',
        ];
        if (!in_array($command, $allowed, true)) {
            abort(422, 'Unknown live sale command');
        }

        return $this->sales->run(
            (string) $request->route('store_id'),
            $command,
            $request->except(['command']),
            $this->actorId(),
            true
        );
    }

    private function actorId(): ?string
    {
        $user = Auth::guard('api')->user();
        $customer = optional(optional($user)->account)->customer;
        if ($customer) {
            return (string) $customer->_id;
        }
        return $user ? (string) $user->getAuthIdentifier() : null;
    }
}
