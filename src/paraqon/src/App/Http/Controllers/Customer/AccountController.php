<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AccountController extends Controller
{
    public function updateAccountVerification(Request $request): array
    {
        $account = $this->account();
        $account->update($request->all());
        return ['message' => 'Updated Verification document successfully'];
    }

    public function getAllCustomerGroups(): Collection
    {
        return $this->customer()->groups()->statusActive()->get();
    }
}
