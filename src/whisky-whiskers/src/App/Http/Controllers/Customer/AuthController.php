<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;

// Models
use App\Models\Customer;

class AuthController extends Controller
{
    public function getCustomerInfo(): Customer
    {
        return $this->customer();
    }
}
