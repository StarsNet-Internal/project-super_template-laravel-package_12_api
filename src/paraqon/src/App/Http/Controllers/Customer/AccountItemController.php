<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Starsnet\Project\Paraqon\App\Services\AccountItemService;

class AccountItemController extends Controller
{
    public function getAll(Request $request, AccountItemService $service): Collection
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(AccountItemService::SCOPES)],
            'purpose' => ['nullable', 'string', Rule::in(AccountItemService::PURPOSES)],
        ]);

        return $service->getAll(
            (string) $this->customer()->id,
            $validated['scope'],
            $validated['purpose'] ?? null
        );
    }
}
