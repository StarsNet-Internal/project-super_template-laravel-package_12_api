<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Traits;

use App\Models\User;

trait ProjectAuthenticationTrait
{
    private function updateLoginIdOnDelete(User $user)
    {
        $users = User::where('login_id', 'LIKE', '%' . $user->login_id . '%')->get();
        $counter = 999 - $users->count() + 1;
        $user->update(['login_id' => $counter . $user->login_id]);
    }
}
