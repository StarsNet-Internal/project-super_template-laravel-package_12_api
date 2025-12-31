<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use Starsnet\Project\TcgBidGame\App\Models\Transaction;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class TransactionController extends Controller
{
    public function getAllTransactions(Request $request)
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();
        
        if (!$gameUser) {
            return collect([]);
        }

        $currencyType = $request->input('currency_type');
        $transactionType = $request->input('transaction_type');

        $query = Transaction::byCustomer($gameUser->_id);

        if ($currencyType || $transactionType) {
            $query->byCurrencyAndTransactionType($currencyType, $transactionType);
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->get();

        return $transactions;
    }

    public function getTransactionById(Request $request)
    {
        $transactionId = $request->route('transaction_id');
        $transaction = Transaction::find($transactionId);

        if (!$transaction) {
            abort(404, 'Transaction not found');
        }

        // Verify ownership
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();
        
        if (!$gameUser || $transaction->customer_id !== $gameUser->_id) {
            abort(403, 'Transaction does not belong to this customer');
        }

        return $transaction;
    }
}

