<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;

// Enums
use App\Enums\StoreType;

// Models
use App\Models\Store;

class OfflineStoreManagementController extends Controller
{
    public function getAllOfflineStores(): Collection
    {
        return Store::statusActive()
            ->whereType(StoreType::OFFLINE->value)
            ->get();
    }
}
