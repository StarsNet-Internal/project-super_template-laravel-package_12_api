<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class UserController extends Controller
{
    public function getCurrency()
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            // Create default GameUser if doesn't exist
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);

            // Give initial energy
            $gameUser->addEnergy(20, 'initial_energy', null, null, [
                'zh' => '初始能量',
                'en' => 'Initial energy',
                'cn' => '初始能量',
            ]);
        }

        // Check and recover energy before returning balance
        $gameUser->checkAndRecoverEnergy();

        return response()->json([
            'coins' => $gameUser->getCoinsBalance(),
            'energy' => $gameUser->getEnergyBalance(),
            'max_energy' => $gameUser->max_energy,
            'last_energy_refill' => $gameUser->last_energy_recovery_check,
        ], 200);
    }

    public function getSettings()
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            // Create default GameUser if doesn't exist
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);

            // Give initial energy
            $gameUser->addEnergy(20, 'initial_energy', null, null, [
                'zh' => '初始能量',
                'en' => 'Initial energy',
                'cn' => '初始能量',
            ]);
        }

        return response()->json($gameUser->settings, 200);
    }

    public function updateSettings(Request $request)
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            // Create default GameUser if doesn't exist
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);

            // Give initial energy
            $gameUser->addEnergy(20, 'initial_energy', null, null, [
                'zh' => '初始能量',
                'en' => 'Initial energy',
                'cn' => '初始能量',
            ]);
        }

        $settings = $request->only([
            'language',
            'sound_enabled',
            'music_enabled',
            'notifications_enabled',
            'vibration_enabled',
        ]);

        $gameUser->updateSettings($settings);

        return response()->json($gameUser->settings, 200);
    }

    public function completeOnboarding()
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            // Create default GameUser if doesn't exist
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);

            // Give initial energy
            $gameUser->addEnergy(20, 'initial_energy', null, null, [
                'zh' => '初始能量',
                'en' => 'Initial energy',
                'cn' => '初始能量',
            ]);
        }

        $gameUser->completeOnboarding();

        return response()->json([
            'message' => 'Onboarding completed',
        ], 200);
    }
}
