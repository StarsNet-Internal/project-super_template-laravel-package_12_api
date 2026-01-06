<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

// Models
use Starsnet\Project\TcgBidGame\App\Models\AdView;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class AdViewController extends Controller
{
    private const DAILY_AD_LIMIT = 10; // Maximum ads per day
    private const ENERGY_PER_AD = 20; // Energy earned per ad view

    public function watchAdOrGetHistory(Request $request)
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
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

        // Check if this is a request for history (has page/limit but no ad_provider)
        $page = $request->input('page');
        $limit = $request->input('limit');
        $adProvider = $request->input('ad_provider');

        // If page/limit is provided and no ad_provider, return history
        if (($page || $limit) && !$adProvider) {
            return $this->getAdViewHistory($gameUser, $request);
        }

        // Otherwise, handle ad viewing initiation
        return $this->watchAd($gameUser, $request);
    }

    /**
     * Watch ad - called by frontend after ad SDK confirms ad was watched
     * Frontend handles ad display and SDK callbacks, then calls this endpoint to claim reward
     */
    private function watchAd(GameUser $gameUser, Request $request)
    {
        $adProvider = $request->input('ad_provider', 'google_ads');

        // Check daily limit (only count completed ads)
        $todayViews = AdView::byCustomer($gameUser->_id)
            ->completed()
            ->today()
            ->count();

        if ($todayViews >= self::DAILY_AD_LIMIT) {
            $nextAvailable = Carbon::tomorrow()->startOfDay();
            return response()->json([
                'error' => [
                    'en' => 'Ad limit reached',
                    'zh' => '已達到廣告觀看限制',
                    'cn' => '已达到广告观看限制',
                ],
                'message' => [
                    'en' => "You have reached the daily ad viewing limit. Next available at: {$nextAvailable->toIso8601String()}",
                    'zh' => "您已達到每日廣告觀看限制。下次可用時間：{$nextAvailable->toIso8601String()}",
                    'cn' => "您已达到每日广告观看限制。下次可用时间：{$nextAvailable->toIso8601String()}",
                ],
                'next_available' => $nextAvailable->toIso8601String(),
            ], 429);
        }

        // Create and immediately complete ad view record
        // Frontend only calls this after ad SDK confirms ad was watched
        $adView = AdView::create([
            'customer_id' => $gameUser->_id,
            'energy_earned' => self::ENERGY_PER_AD,
            'ad_provider' => $adProvider,
            'status' => 'completed',
            'started_at' => now(),
            'viewed_at' => now(),
            'completed_at' => now(),
        ]);
        $adView->associateCustomer($gameUser);

        // Add energy via transaction
        $gameUser->addEnergy(
            self::ENERGY_PER_AD,
            'ad_reward',
            $adView->_id,
            'ad_view',
            [
                'zh' => '觀看廣告獲得的能量',
                'en' => 'Energy earned from watching ad',
                'cn' => '观看广告获得的能量',
            ]
        );

        return response()->json([
            'ad_view_id' => $adView->_id,
            'energy_earned' => self::ENERGY_PER_AD,
            'new_energy_balance' => $gameUser->getEnergyBalance(),
            'ad_provider' => $adProvider,
            'viewed_at' => $adView->viewed_at,
        ], 200);
    }

    private function getAdViewHistory(GameUser $gameUser, Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 20);

        $adViews = AdView::byCustomer($gameUser->_id)
            ->orderBy('viewed_at', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        return $adViews;
    }
}
