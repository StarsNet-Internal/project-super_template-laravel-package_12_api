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

        $activeSessionsByGameId = [];
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();
        if ($gameUser) {
            $gameIds = $games->pluck('_id')->all();
            $sessions = GameSession::whereIn('game_id', $gameIds)
                ->byCustomer($gameUser->_id)
                ->active()
                ->get();
            $activeSessionsByGameId = $sessions->keyBy(function ($s) {
                return (string) $s->game_id;
            })->all();
        }

        return $games->map(function (Game $game) use ($activeSessionsByGameId) {
            $item = $game->toArray();
            unset($item['levels_by_difficulty'], $item['difficulty']);

            $session = $activeSessionsByGameId[(string) $game->_id] ?? null;
            if ($session) {
                $item['game_state'] = $session->game_state;
                $item['difficulty_params'] = null;
                $item['is_active'] = true;
            } else {
                $item['game_state'] = null;
                $item['difficulty_params'] = $game->getResolvedDifficultyParams();
                $item['is_active'] = false;
            }

            return $item;
        });
    }

    public function startGame(Request $request)
    {
        $gameId = $request->route('game_id');
        $game = Game::find($gameId);

        if (!$game) {
            abort(404, json_encode([
                'en' => 'Game not found',
                'zh' => '找不到遊戲',
                'cn' => '找不到游戏',
            ]));
        }

        if (!$game->is_active) {
            abort(400, json_encode([
                'en' => 'Game is not active',
                'zh' => '遊戲未啟用',
                'cn' => '游戏未启用',
            ]));
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
                'error' => [
                    'en' => 'Insufficient energy',
                    'zh' => '能量不足',
                    'cn' => '能量不足',
                ],
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
            'difficulty_params' => $game->getResolvedDifficultyParams(),
            'game_state' => $session->game_state,
        ], 200);
    }

    /**
     * Get current user's active session (session with null outcome) for restoring state after app exit.
     */
    public function getActiveSession(Request $request)
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();
        if (!$gameUser) {
            return response()->json(['session' => null], 200);
        }

        $session = GameSession::byCustomer($gameUser->_id)
            ->whereNull('outcome')
            ->orderBy('started_at', 'desc')
            ->first();

        if (!$session) {
            return response()->json(['session' => null], 200);
        }

        $session->load('game');
        $game = $session->game;

        return response()->json([
            'session' => [
                'session_id' => $session->_id,
                'game_id' => $session->game_id,
                'game_title' => $game ? $game->title : null,
                'difficulty_params' => $game ? ($game->difficulty_params ?? []) : [],
                'game_state' => $session->game_state,
                'started_at' => $session->started_at,
                'expire_at' => $session->expire_at,
            ],
        ], 200);
    }

    /**
     * Save game state (e.g. when user exits mid-round) for later restore.
     */
    public function saveGameState(Request $request)
    {
        $gameId = $request->route('game_id');
        $sessionId = $request->route('session_id');
        $gameState = $request->input('game_state');

        if ($gameState === null || $gameState === '') {
            abort(400, json_encode([
                'en' => 'game_state is required',
                'zh' => '需要遊戲狀態',
                'cn' => '需要游戏状态',
            ]));
        }

        if (!is_string($gameState)) {
            abort(400, json_encode([
                'en' => 'game_state must be a string',
                'zh' => '遊戲狀態必須為字串',
                'cn' => '游戏状态必须为字符串',
            ]));
        }

        $game = Game::find($gameId);
        if (!$game) {
            abort(404, json_encode([
                'en' => 'Game not found',
                'zh' => '找不到遊戲',
                'cn' => '找不到游戏',
            ]));
        }

        $session = GameSession::find($sessionId);
        if (!$session) {
            abort(404, json_encode([
                'en' => 'Session not found',
                'zh' => '找不到會話',
                'cn' => '找不到会话',
            ]));
        }

        if ($session->game_id !== $game->_id) {
            abort(400, json_encode([
                'en' => 'Session does not belong to this game',
                'zh' => '會話不屬於此遊戲',
                'cn' => '会话不属于此游戏',
            ]));
        }

        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();
        if (!$gameUser || $session->customer_id !== $gameUser->_id) {
            abort(403, json_encode([
                'en' => 'Not authorized to update this session',
                'zh' => '無權更新此會話',
                'cn' => '无权更新此会话',
            ]));
        }

        if (!$session->isActive()) {
            abort(400, json_encode([
                'en' => 'Session is no longer active',
                'zh' => '會話已結束或已過期',
                'cn' => '会话已结束或已过期',
            ]));
        }

        $session->saveGameState($gameState);

        return response()->json([
            'session_id' => $session->_id,
            'game_id' => $game->_id,
            'saved_at' => now()->toIso8601String(),
        ], 200);
    }

    public function endGame(Request $request)
    {
        $gameId = $request->route('game_id');
        $sessionId = $request->input('session_id');
        $outcome = $request->input('outcome');

        if (!$sessionId) {
            abort(400, json_encode([
                'en' => 'Session ID is required',
                'zh' => '需要會話ID',
                'cn' => '需要会话ID',
            ]));
        }

        if (!$outcome) {
            abort(400, json_encode([
                'en' => 'Outcome is required',
                'zh' => '需要遊戲結果',
                'cn' => '需要游戏结果',
            ]));
        }

        if (!in_array($outcome, ['win', 'lose'])) {
            return response()->json([
                'error' => [
                    'en' => "Invalid outcome. Must be 'win' or 'lose'",
                    'zh' => '無效的結果。必須是「win」或「lose」',
                    'cn' => '无效的结果。必须是「win」或「lose」',
                ],
                'provided' => $outcome,
            ], 400);
        }

        $game = Game::find($gameId);
        if (!$game) {
            abort(404, json_encode([
                'en' => 'Game not found',
                'zh' => '找不到遊戲',
                'cn' => '找不到游戏',
            ]));
        }

        $session = GameSession::find($sessionId);
        if (!$session) {
            abort(404, json_encode([
                'en' => 'Session not found',
                'zh' => '找不到會話',
                'cn' => '找不到会话',
            ]));
        }

        if ($session->game_id !== $game->_id) {
            abort(400, json_encode([
                'en' => 'Session does not belong to this game',
                'zh' => '會話不屬於此遊戲',
                'cn' => '会话不属于此游戏',
            ]));
        }

        $gameUser = GameUser::find($session->customer_id);
        if (!$gameUser) {
            abort(404, json_encode([
                'en' => 'Game user not found',
                'zh' => '找不到遊戲用戶',
                'cn' => '找不到游戏用户',
            ]));
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
