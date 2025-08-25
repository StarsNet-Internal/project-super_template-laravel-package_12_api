<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;

// Enums
use App\Enums\Status;

// Models
use App\Models\Store;

class ServiceController extends Controller
{
    public function archiveStores(): array
    {
        $now = now();
        Store::where('end_datetime', '<=', $now)
            ->where('status', Status::ACTIVE->value)
            ->update(['status' => Status::ARCHIVED->value]);

        return [
            'message' => 'Archived stores'
        ];
    }
}
