<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function updateAccountVerification(Request $request): array
    {
        $this->account()->update($request->all());

        return [
            'message' => 'Updated Verification successfully'
        ];
    }
}
