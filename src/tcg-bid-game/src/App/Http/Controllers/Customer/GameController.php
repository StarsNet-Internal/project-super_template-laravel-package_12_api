<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use Starsnet\Project\TcgBidGame\App\Models\Game;
use Starsnet\Project\TcgBidGame\App\Models\GameSession;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class GameController extends Controller
{
    public function getAllGames()
    {
        $games = Game::active()
            ->orderBy('created_at', 'desc')
            ->get();

        return $games;
    }

    public function startGame(Request $request)
    {
        $gameId = $request->route('game_id');
        $game = Game::find($gameId);

        if (!$game) {
            abort(404, 'Game not found');
        }

        if (!$game->is_active) {
            abort(400, 'Game is not active');
        }

        // Get or create GameUser
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

        // Check and recover energy before checking balance
        $gameUser->checkAndRecoverEnergy();

        // Check if user has enough energy
        if (!$gameUser->hasEnoughEnergy($game->energy_cost)) {
            return response()->json([
                'error' => 'Insufficient energy',
                'required' => $game->energy_cost,
                'current' => $gameUser->getEnergyBalance(),
                'game_id' => $game->_id,
                'game_title' => $game->title,
            ], 400);
        }

        // Deduct energy via transaction
        $gameUser->deductEnergy(
            $game->energy_cost,
            'game_play',
            $game->_id,
            'game',
            [
                'zh' => "遊玩{$game->title['zh']}消耗能量",
                'en' => "Spent energy playing {$game->title['en']}",
                'cn' => "游玩{$game->title['cn']}消耗能量",
            ]
        );

        // Create game session
        $session = GameSession::create([
            'game_id' => $game->_id,
            'customer_id' => $gameUser->_id,
            'energy_cost' => $game->energy_cost,
            'energy_spent' => $game->energy_cost,
        ]);

        $session->associateGame($game);
        $session->associateCustomer($gameUser);
        $session->start();

        return response()->json([
            'session_id' => $session->_id,
            'game_id' => $game->_id,
            'energy_cost' => $game->energy_cost,
            'energy_spent' => $game->energy_cost,
            'new_energy_balance' => $gameUser->getEnergyBalance(),
            'started_at' => $session->started_at,
            'expire_at' => $session->expire_at,
        ], 200);
    }

    public function endGame(Request $request)
    {
        $gameId = $request->route('game_id');
        $sessionId = $request->input('session_id');
        $outcome = $request->input('outcome');

        if (!$sessionId) {
            abort(400, 'Session ID is required');
        }

        if (!$outcome) {
            abort(400, 'Outcome is required');
        }

        if (!in_array($outcome, ['win', 'lose'])) {
            return response()->json([
                'error' => "Invalid outcome. Must be 'win' or 'lose'",
                'provided' => $outcome,
            ], 400);
        }

        $game = Game::find($gameId);
        if (!$game) {
            abort(404, 'Game not found');
        }

        $session = GameSession::find($sessionId);
        if (!$session) {
            abort(404, 'Session not found');
        }

        if ($session->game_id !== $game->_id) {
            abort(400, 'Session does not belong to this game');
        }

        $gameUser = GameUser::find($session->customer_id);
        if (!$gameUser) {
            abort(404, 'Game user not found');
        }

        // Calculate coins earned
        $coinsEarned = $outcome === 'win' ? $game->coins_earned : 0;

        // End the session
        $session->end($outcome, $coinsEarned);

        // Add coins if won via transaction
        if ($outcome === 'win' && $coinsEarned > 0) {
            $gameUser->addCoins(
                $coinsEarned,
                'game_earnings',
                $session->_id,
                'game_session',
                [
                    'zh' => "遊玩{$game->title['zh']}獲得的獎勵",
                    'en' => "Earned from playing {$game->title['en']}",
                    'cn' => "游玩{$game->title['cn']}获得的奖励",
                ]
            );
        }

        return response()->json([
            'session_id' => $session->_id,
            'game_id' => $game->_id,
            'game_title' => $game->title,
            'outcome' => $outcome,
            'coins_earned' => $coinsEarned,
            'new_coin_balance' => $gameUser->getCoinsBalance(),
            'ended_at' => $session->ended_at,
        ], 200);
    }
}
