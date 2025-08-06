<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Models
use App\Models\Account;
use App\Models\User;
use App\Models\VerificationCode;

class VerificationCodeController extends Controller
{
    public function getAllVerificationCodes(Request $request): Collection
    {
        $codes = VerificationCode::orderByDesc('created_at')
            ->take((int) $request->input('limit', 100))
            ->get();

        $userIDs = $codes->pluck('user_id')->unique();
        $accounts = Account::whereIn('user_id', $userIDs)->with('user')->get();

        $users = $accounts->map(function ($account) {
            if (!$account->user) return null;
            $user = $account->user->toArray();
            $user['account'] = $account->makeHidden('user')->toArray();
            return $user;
        })
            ->keyBy(['id']);

        return $codes->each(function ($code) use ($users) {
            $code->user = $users->get($code->user_id);
        });
    }
}
