<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Account;

class AccountController extends Controller
{
    public function updateAccountVerification(Request $request): array
    {
        /** @var ?Account $account */
        $account = Account::find($request->route('id'));
        if (is_null($account)) abort(404, 'Account not found');

        $account->update($request->all());

        return ['message' => 'Updated Verification document successfully'];
    }

    public function updateAccountDetails(Request $request): array
    {
        /** @var ?Account $account */
        $account = Account::find($request->route('id'));
        if (is_null($account)) abort(404, 'Account not found');

        $account->update($request->all());

        return ['message' => 'Updated Account Details successfully'];
    }
}
