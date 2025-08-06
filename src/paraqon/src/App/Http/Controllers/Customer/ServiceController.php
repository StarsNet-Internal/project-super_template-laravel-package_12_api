<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function checkCurrentTime(): array
    {
        return ['now_time' => now()];
    }

    public function checkOtherTimeZone(Request $request): array
    {
        return ['now_time' => now()->addHours((int) $request->timezone)];
    }
}
