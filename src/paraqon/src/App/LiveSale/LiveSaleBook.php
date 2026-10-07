<?php

namespace Starsnet\Project\Paraqon\App\LiveSale;

/**
 * In-memory book for one live sale.
 *
 * The chant writes this book and a sale log. It does not write bid_histories
 * or the auction lot and store fields that the online change-stream listener
 * watches. Start and end of the sale are the exception: the caller updates
 * the store status so the existing live-auction emails still go out.
 */
class LiveSaleBook
{
    private string $storeId;
    private int $sequence = 0;
    private string $status = 'idle';
    private ?string $currentLotId = null;

    /** @var array<string, array<string, mixed>> */
    private array $lots = [];

    public static function empty(string $storeId): self
    {
        $book = new self();
        $book->storeId = $storeId;
        return $book;
    }

    public static function fromArray(array $data): self
    {
        $book = self::empty((string) ($data['store_id'] ?? ''));
        $book->sequence = (int) ($data['sequence'] ?? 0);
        $book->status = (string) ($data['status'] ?? 'idle');
        $book->currentLotId = $data['current_lot_id'] ?? null;
        foreach ($data['lots'] ?? [] as $lot) {
            $lot = (array) self::plain($lot);
            $lot['advance'] = $book->normaliseAdvance(
                isset($lot['advance']) && is_array($lot['advance']) ? $lot['advance'] : null
            );
            $book->lots[(string) $lot['lot_id']] = $lot;
        }
        return $book;
    }

    /**
     * Catalogue shown before Start. Nothing is saved and the sequence stays at 0.
     *
     * @param array<int, array<string, mixed>> $lots
     */
    public function loadPreview(array $lots): void
    {
        $this->loadCatalogue($lots);
        $this->currentLotId = $this->firstLotId();
        $this->sequence = 0;
        $this->status = 'idle';
    }

    public function toArray(): array
    {
        return [
            'store_id' => $this->storeId,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'current_lot_id' => $this->currentLotId,
            'lots' => array_values($this->lots),
        ];
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(string $command, array $payload): array
    {
        $at = (string) ($payload['at'] ?? gmdate('c'));
        $summary = match ($command) {
            'start_sale' => $this->startSale($payload, $at),
            'prepare_lot' => $this->prepareLot($payload, $at),
            'open_lot' => $this->openLot($payload, $at),
            'set_asking_price' => $this->setAskingPrice($payload, $at),
            'set_increment' => $this->setIncrement($payload, $at),
            'accept_bid' => $this->acceptBid($payload, $at),
            'submit_online_bid' => $this->submitOnlineBid($payload, $at),
            'accept_pending' => $this->acceptPending($payload, $at),
            'reject_pending' => $this->rejectPending($payload, $at),
            'warn' => $this->warn($payload, $at),
            'sell' => $this->sell($at),
            'pass' => $this->pass($at),
            'undo_latest_bid' => $this->undoLatestBid($payload, $at),
            'reopen' => $this->reopen($payload, $at),
            'end_sale' => $this->endSale($at),
            default => throw new LiveSaleException('Unknown live sale command'),
        };

        $this->sequence++;
        $summary['command'] = $command;
        $summary['sequence'] = $this->sequence;
        $summary['at'] = $at;
        return $summary;
    }

    public function publicPayload(string $at): array
    {
        return $this->payload(false, $at);
    }

    public function clerkPayload(string $at): array
    {
        return $this->payload(true, $at);
    }

    /**
     * @param array<int, array<string, mixed>> $lots
     */
    private function startSale(array $payload, string $at): array
    {
        if ($this->status === 'running') {
            throw new LiveSaleException('The sale is already running');
        }
        if ($this->status === 'ended') {
            throw new LiveSaleException('The sale has ended');
        }
        $this->loadCatalogue($payload['lots'] ?? []);
        $this->status = 'running';
        $this->currentLotId = $this->firstLotId();
        return ['lot_id' => $this->currentLotId];
    }

    /**
     * @param array<int, array<string, mixed>> $lots
     */
    private function loadCatalogue(array $lots): void
    {
        if ($lots === []) {
            throw new LiveSaleException('This sale has no lots');
        }
        $this->lots = [];
        foreach ($lots as $lot) {
            $lotId = (string) ($lot['lot_id'] ?? '');
            if ($lotId === '') {
                throw new LiveSaleException('A lot is missing its id');
            }
            $settings = $lot['bid_incremental_settings'] ?? [];
            if (!is_array($settings)) {
                $settings = [];
            }
            if (!isset($settings['increments']) || !is_array($settings['increments'])) {
                $settings['increments'] = [];
            }
            if (!isset($settings['estimate_price']) || !is_array($settings['estimate_price'])) {
                $settings['estimate_price'] = ['min' => 0, 'max' => 0];
            }
            $title = $lot['title'] ?? ['en' => '', 'zh' => '', 'cn' => ''];
            $this->lots[$lotId] = [
                'lot_id' => $lotId,
                'lot_number' => (int) ($lot['lot_number'] ?? 0),
                'product_id' => $lot['product_id'] ?? null,
                'title' => $title,
                'starting_price' => $this->money($lot['starting_price'] ?? 0),
                'reserve_price' => $this->money($lot['reserve_price'] ?? 0),
                'increment_override' => null,
                'bid_incremental_settings' => $settings,
                'is_permission_required' => (bool) ($lot['is_permission_required'] ?? false),
                'permission_requests' => $lot['permission_requests'] ?? [],
                'created_at' => $lot['created_at'] ?? null,
                'advance' => $this->normaliseAdvance(
                    isset($lot['advance']) && is_array($lot['advance']) ? $lot['advance'] : null
                ),
                'state' => 'upcoming',
                'asking_price' => null,
                'opening_asking' => null,
                'warning' => null,
                'bids' => [],
                'pending' => [],
                'notices' => [],
                'hammer_price' => null,
                'hammer_paddle_id' => null,
                'hammer_customer_id' => null,
            ];
        }
    }

    private function prepareLot(array $payload, string $at): array
    {
        $this->requireRunning();
        $lotId = (string) ($payload['lot_id'] ?? $this->nextUpcomingLotId() ?? '');
        $lot = $this->requireLot($lotId);
        if (!in_array($lot['state'], ['upcoming', 'passed'], true)) {
            throw new LiveSaleException('That lot cannot be prepared');
        }
        $this->requireNoLotOnTheBlock($lotId);
        $lot['state'] = 'preparing';
        $this->lots[$lotId] = $lot;
        $this->currentLotId = $lotId;
        return ['lot_id' => $lotId];
    }

    private function openLot(array $payload, string $at): array
    {
        $this->requireRunning();
        $lotId = (string) ($payload['lot_id'] ?? $this->currentLotId ?? '');
        $lot = $this->requireLot($lotId);
        if ($lot['state'] !== 'preparing') {
            throw new LiveSaleException('Prepare the lot before opening it');
        }
        $this->requireNoLotOnTheBlock($lotId);
        $opening = $lot['starting_price'];
        $lot['state'] = 'open';
        $lot['opening_asking'] = $opening;
        $lot['asking_price'] = $opening;
        $lot['warning'] = null;
        $this->lots[$lotId] = $lot;
        $this->currentLotId = $lotId;
        return ['lot_id' => $lotId];
    }

    private function setAskingPrice(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $amount = $this->money($payload['amount'] ?? null);
        $current = $this->currentBid($lot);
        if ($current !== null && !$this->greater($amount, $current)) {
            throw new LiveSaleException('The asking price has to be above the current bid');
        }
        if ($current === null && $amount < 0) {
            throw new LiveSaleException('The asking price has to be above the current bid');
        }
        $lot['asking_price'] = $amount;
        $lot['state'] = 'open';
        $lot['warning'] = null;
        $lot['pending'] = [];
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'amount' => $amount];
    }

    private function setIncrement(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $increment = $this->money($payload['increment'] ?? null);
        if (!$this->greater($increment, 0)) {
            throw new LiveSaleException('The increment has to be greater than zero');
        }
        $lot['increment_override'] = $increment;
        $current = $this->currentBid($lot);
        if ($current !== null) {
            $lot['asking_price'] = round($current + $increment, 2);
            $lot['pending'] = [];
        }
        $lot['state'] = 'open';
        $lot['warning'] = null;
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'increment' => $increment];
    }

    private function acceptBid(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $amount = $this->money($payload['amount'] ?? null);
        $source = (string) ($payload['source'] ?? '');
        if (!in_array($source, ['floor', 'phone', 'house'], true)) {
            throw new LiveSaleException('Choose a floor, phone, or house bid');
        }
        $current = $this->currentBid($lot);
        if ($current !== null && !$this->greater($amount, $current)) {
            throw new LiveSaleException('The bid has to be above the current bid');
        }
        if ($current === null && $amount < 0) {
            throw new LiveSaleException('The bid has to be above the current bid');
        }
        if ($source === 'house') {
            $reserve = (float) $lot['reserve_price'];
            if ($reserve <= 0 || !$this->greater($reserve, $amount)) {
                throw new LiveSaleException('A house bid has to stay under the reserve');
            }
        }
        $paddleId = $payload['paddle_id'] ?? null;
        if (in_array($source, ['floor', 'phone'], true) && ($paddleId === null || $paddleId === '')) {
            throw new LiveSaleException('A floor or phone bid needs a paddle');
        }
        $bid = [
            'id' => $this->newId(),
            'amount' => $amount,
            'paddle_id' => $paddleId,
            'customer_id' => $payload['customer_id'] ?? null,
            'source' => $source,
            'created_at' => $at,
        ];
        $lot['bids'][] = $bid;
        $lot['pending'] = [];
        $lot['state'] = 'open';
        $lot['warning'] = null;
        $lot['asking_price'] = round($amount + $this->incrementFor($lot, $amount), 2);
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'bid_id' => $bid['id'], 'amount' => $amount];
    }

    private function submitOnlineBid(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $amount = $this->money($payload['amount'] ?? null);
        $asking = (float) ($lot['asking_price'] ?? 0);
        if (!$this->same($amount, $asking)) {
            throw new LiveSaleException('The asking price has changed');
        }
        $current = $this->currentBid($lot);
        if ($current !== null && !$this->greater($amount, $current)) {
            throw new LiveSaleException('The asking price has changed');
        }
        $customerId = (string) ($payload['customer_id'] ?? '');
        if ($customerId === '') {
            throw new LiveSaleException('Register for a paddle before bidding');
        }
        $latest = $this->latestBid($lot);
        if ($latest && (string) ($latest['customer_id'] ?? '') === $customerId) {
            throw new LiveSaleException('The bid is already with you');
        }
        $pending = [
            'id' => $this->newId(),
            'amount' => $amount,
            'customer_id' => $customerId,
            'paddle_id' => $payload['paddle_id'] ?? null,
            'created_at' => $at,
        ];
        $lot['pending'] = array_values(array_filter(
            $lot['pending'],
            fn ($item) => (string) ($item['customer_id'] ?? '') !== $customerId
        ));
        $lot['pending'][] = $pending;
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'pending_id' => $pending['id']];
    }

    private function acceptPending(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $pendingId = (string) ($payload['pending_id'] ?? '');
        $pending = null;
        foreach ($lot['pending'] as $item) {
            if ((string) $item['id'] === $pendingId) {
                $pending = $item;
                break;
            }
        }
        if ($pending === null) {
            throw new LiveSaleException('That online bid is no longer waiting');
        }
        $amount = (float) $pending['amount'];
        if (!$this->same($amount, (float) $lot['asking_price'])) {
            throw new LiveSaleException('The asking price has changed');
        }
        $bid = [
            'id' => $this->newId(),
            'amount' => $amount,
            'paddle_id' => $pending['paddle_id'],
            'customer_id' => $pending['customer_id'],
            'source' => 'online',
            'created_at' => $at,
        ];
        $lot['bids'][] = $bid;
        $lot['pending'] = [];
        $lot['state'] = 'open';
        $lot['warning'] = null;
        $lot['asking_price'] = round($amount + $this->incrementFor($lot, $amount), 2);
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'bid_id' => $bid['id'], 'amount' => $amount];
    }

    private function rejectPending(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $pendingId = (string) ($payload['pending_id'] ?? '');
        $before = count($lot['pending']);
        $lot['pending'] = array_values(array_filter(
            $lot['pending'],
            fn ($item) => (string) $item['id'] !== $pendingId
        ));
        if (count($lot['pending']) === $before) {
            throw new LiveSaleException('That online bid is no longer waiting');
        }
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'pending_id' => $pendingId];
    }

    private function warn(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $action = (string) ($payload['action'] ?? '');
        if (!in_array($action, ['FAIR_WARNING', 'LAST_WARNING', 'LAST_CHANCE'], true)) {
            throw new LiveSaleException('Unknown warning');
        }
        if ($lot['state'] === 'fair_warning' || $lot['state'] === 'open') {
            $lot['state'] = 'fair_warning';
            $lot['warning'] = $action;
            $lot['notices'][] = ['action' => $action, 'created_at' => $at];
            $this->lots[$lot['lot_id']] = $lot;
            return ['lot_id' => $lot['lot_id'], 'action' => $action];
        }
        throw new LiveSaleException('Open the lot before giving a warning');
    }

    private function sell(string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $latest = $this->latestBid($lot);
        if ($latest === null) {
            throw new LiveSaleException('There is no bid to sell');
        }
        $reserve = (float) $lot['reserve_price'];
        if ($reserve > 0 && $this->greater($reserve, (float) $latest['amount'])) {
            throw new LiveSaleException('The reserve has not been met');
        }
        $lot['state'] = 'sold';
        $lot['warning'] = null;
        $lot['pending'] = [];
        $lot['hammer_price'] = $latest['amount'];
        $lot['hammer_paddle_id'] = $latest['paddle_id'];
        $lot['hammer_customer_id'] = $latest['customer_id'];
        $this->lots[$lot['lot_id']] = $lot;
        return ['lot_id' => $lot['lot_id'], 'amount' => $latest['amount']];
    }

    private function pass(string $at): array
    {
        $this->requireRunning();
        $lotId = (string) ($this->currentLotId ?? '');
        $lot = $this->requireLot($lotId);
        if (!in_array($lot['state'], ['preparing', 'open', 'fair_warning'], true)) {
            throw new LiveSaleException('That lot is not on the block');
        }
        $lot['state'] = 'passed';
        $lot['warning'] = null;
        $lot['pending'] = [];
        $lot['hammer_price'] = null;
        $lot['hammer_paddle_id'] = null;
        $lot['hammer_customer_id'] = null;
        $this->lots[$lotId] = $lot;
        return ['lot_id' => $lotId];
    }

    private function undoLatestBid(array $payload, string $at): array
    {
        $lot = $this->requireOnTheBlock();
        $latest = $this->latestBid($lot);
        if ($latest === null) {
            throw new LiveSaleException('There is no bid to undo');
        }
        $bidId = (string) ($payload['bid_id'] ?? $latest['id']);
        if ($bidId !== (string) $latest['id']) {
            throw new LiveSaleException('That bid is no longer the latest');
        }
        array_pop($lot['bids']);
        $lot['pending'] = [];
        $lot['state'] = 'open';
        $lot['warning'] = null;
        $lot['asking_price'] = (float) $latest['amount'];
        if ($lot['bids'] === [] && $lot['opening_asking'] !== null) {
            $lot['asking_price'] = (float) $lot['opening_asking'];
        }
        $this->lots[$lot['lot_id']] = $lot;
        return [
            'lot_id' => $lot['lot_id'],
            'bid_id' => $latest['id'],
            'amount' => $latest['amount'],
        ];
    }

    private function reopen(array $payload, string $at): array
    {
        $this->requireRunning();
        $lotId = (string) ($payload['lot_id'] ?? $this->currentLotId ?? '');
        $lot = $this->requireLot($lotId);
        if (!in_array($lot['state'], ['sold', 'passed'], true)) {
            throw new LiveSaleException('Only a sold or passed lot can be reopened');
        }
        $this->requireNoLotOnTheBlock($lotId);
        $lot['state'] = 'open';
        $lot['warning'] = null;
        $lot['pending'] = [];
        $lot['hammer_price'] = null;
        $lot['hammer_paddle_id'] = null;
        $lot['hammer_customer_id'] = null;
        $current = $this->currentBid($lot);
        $lot['asking_price'] = $current === null
            ? (float) ($lot['opening_asking'] ?? $lot['starting_price'])
            : round($current + $this->incrementFor($lot, $current), 2);
        $this->lots[$lotId] = $lot;
        $this->currentLotId = $lotId;
        return ['lot_id' => $lotId];
    }

    private function endSale(string $at): array
    {
        $this->requireRunning();
        foreach ($this->lots as $lot) {
            if (in_array($lot['state'], ['open', 'fair_warning', 'preparing'], true)) {
                throw new LiveSaleException('Sell or pass the lot on the block before ending the sale');
            }
        }
        $this->status = 'ended';
        return ['lot_id' => $this->currentLotId];
    }

    private function payload(bool $clerk, string $at): array
    {
        $current = $this->currentLotId ? ($this->lots[$this->currentLotId] ?? null) : null;
        $lots = array_values($this->lots);
        usort($lots, fn ($a, $b) => $a['lot_number'] <=> $b['lot_number']);

        $publicLots = [];
        foreach ($lots as $lot) {
            $flags = $this->flagsFor($lot['state']);
            $latest = $this->latestBid($lot);
            $row = [
                '_id' => $lot['lot_id'],
                'product_id' => $lot['product_id'],
                'store_id' => $this->storeId,
                'lot_number' => $lot['lot_number'],
                'title' => $lot['title'],
                'starting_price' => $lot['starting_price'],
                'current_bid' => $lot['state'] === 'sold'
                    ? $lot['hammer_price']
                    : ($latest['amount'] ?? 0),
                'status' => $flags['status'],
                'is_disabled' => $flags['is_disabled'],
                'is_closed' => $flags['is_closed'],
                'is_permission_required' => $lot['is_permission_required'],
                'bid_incremental_settings' => $lot['bid_incremental_settings'],
                'created_at' => $lot['created_at'],
                'leading_paddle_id' => $latest['paddle_id'] ?? null,
                'live_state' => $lot['state'],
            ];
            if ($clerk) {
                $row['reserve_price'] = $lot['reserve_price'];
                $row['reserve_met'] = $this->reserveMet($lot);
                $row['advance'] = $lot['advance'];
                $row['hammer_customer_id'] = $lot['hammer_customer_id'];
            }
            $publicLots[] = $row;
        }

        $histories = [];
        $events = [];
        $pending = [];
        if ($current) {
            foreach ($current['bids'] as $bid) {
                $row = [
                    'current_bid' => $bid['amount'],
                    'created_at' => $bid['created_at'],
                    'paddle_label' => $this->paddleLabel($bid),
                    'source' => $bid['source'],
                    'is_hidden' => false,
                    'bid_id' => $bid['id'],
                ];
                if ($clerk) {
                    $row['winning_bid_customer_id'] = $bid['customer_id'];
                    $row['paddle_id'] = $bid['paddle_id'];
                }
                $histories[] = $row;
            }
            foreach ($current['notices'] as $notice) {
                $events[] = [
                    'action' => $notice['action'],
                    'value_1' => $current['lot_id'],
                    'created_at' => $notice['created_at'],
                    'is_hidden' => false,
                ];
            }
            if ($clerk) {
                foreach ($current['pending'] as $item) {
                    $pending[] = [
                        'id' => $item['id'],
                        'amount' => $item['amount'],
                        'paddle_id' => $item['paddle_id'],
                        'paddle_label' => $this->paddleLabel([
                            'source' => 'online',
                            'paddle_id' => $item['paddle_id'],
                        ]),
                        'customer_id' => $item['customer_id'],
                        'created_at' => $item['created_at'],
                    ];
                }
            }
        }

        $latest = $current ? $this->latestBid($current) : null;
        $undo = null;
        if ($clerk && $current && $latest && in_array($current['state'], ['open', 'fair_warning'], true)) {
            $undo = [
                'bid_id' => $latest['id'],
                'amount' => $latest['amount'],
                'paddle_label' => $this->paddleLabel($latest),
            ];
        }

        $body = [
            'sequence' => $this->sequence,
            'time' => $at,
            'started' => $this->status !== 'idle',
            'ended' => $this->status === 'ended',
            'current_lot_id' => $this->currentLotId,
            'lots' => $publicLots,
            'histories' => $histories,
            'events' => $events,
            'asking_price' => $current['asking_price'] ?? null,
            'increment' => $current ? $this->incrementFor($current, (float) ($latest['amount'] ?? $current['starting_price'])) : null,
            'has_pending' => $current ? count($current['pending']) > 0 : false,
            'warning' => $current['warning'] ?? null,
            'highest_advanced_bid' => null,
        ];
        if ($clerk) {
            $body['clerk'] = true;
            $body['pending'] = $pending;
            $body['undo'] = $undo;
            if ($current && !empty($current['advance'])) {
                $body['highest_advanced_bid'] = [
                    'customer_id' => $current['advance']['customer_id'] ?? null,
                    'bid' => $current['advance']['amount'] ?? null,
                    'paddle_id' => $current['advance']['paddle_id'] ?? null,
                ];
            }
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $lot
     * @return array{status: string, is_disabled: bool, is_closed: bool}
     */
    private function flagsFor(string $state): array
    {
        return match ($state) {
            'preparing' => ['status' => 'ARCHIVED', 'is_disabled' => false, 'is_closed' => true],
            'open', 'fair_warning' => ['status' => 'ACTIVE', 'is_disabled' => false, 'is_closed' => false],
            'sold' => ['status' => 'ACTIVE', 'is_disabled' => true, 'is_closed' => false],
            'passed' => ['status' => 'ACTIVE', 'is_disabled' => true, 'is_closed' => true],
            default => ['status' => 'ARCHIVED', 'is_disabled' => false, 'is_closed' => false],
        };
    }

    /**
     * @param array<string, mixed> $bid
     */
    private function paddleLabel(array $bid): string
    {
        $paddle = $bid['paddle_id'] ?? null;
        return match ($bid['source'] ?? '') {
            'phone' => $paddle ? 'Phone #' . $paddle : 'Phone',
            'online' => $paddle ? 'Paddle #' . $paddle : 'Online',
            default => 'Floor bid',
        };
    }

    /**
     * @param array<string, mixed> $lot
     */
    private function incrementFor(array $lot, float $price): float
    {
        if ($lot['increment_override'] !== null) {
            return (float) $lot['increment_override'];
        }
        foreach ($lot['bid_incremental_settings']['increments'] as $rule) {
            $rule = (array) $rule;
            $from = (float) ($rule['from'] ?? 0);
            $to = (float) ($rule['to'] ?? 0);
            if ($price >= $from && $price < $to) {
                return (float) ($rule['increment'] ?? 0);
            }
        }
        return 0.0;
    }

    /**
     * @param array<string, mixed> $lot
     */
    private function reserveMet(array $lot): bool
    {
        $reserve = (float) $lot['reserve_price'];
        if ($reserve <= 0) {
            return true;
        }
        $latest = $this->latestBid($lot);
        if ($latest === null) {
            return false;
        }
        return !$this->greater($reserve, (float) $latest['amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOnTheBlock(): array
    {
        $this->requireRunning();
        $lot = $this->requireLot((string) $this->currentLotId);
        if (!in_array($lot['state'], ['open', 'fair_warning'], true)) {
            throw new LiveSaleException('Open the lot before taking a bid');
        }
        return $lot;
    }

    private function requireRunning(): void
    {
        if ($this->status === 'idle') {
            throw new LiveSaleException('Start the sale first');
        }
        if ($this->status === 'ended') {
            throw new LiveSaleException('The sale has ended');
        }
    }

    private function requireNoLotOnTheBlock(string $exceptLotId): void
    {
        foreach ($this->lots as $lot) {
            if ($lot['lot_id'] === $exceptLotId) {
                continue;
            }
            if (in_array($lot['state'], ['preparing', 'open', 'fair_warning'], true)) {
                throw new LiveSaleException('Another lot is still on the block');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireLot(string $lotId): array
    {
        if ($lotId === '' || !isset($this->lots[$lotId])) {
            throw new LiveSaleException('Lot not found');
        }
        return $this->lots[$lotId];
    }

    /**
     * @param array<string, mixed> $lot
     * @return array<string, mixed>|null
     */
    private function latestBid(array $lot): ?array
    {
        if ($lot['bids'] === []) {
            return null;
        }
        return $lot['bids'][count($lot['bids']) - 1];
    }

    /**
     * @param array<string, mixed> $lot
     */
    private function currentBid(array $lot): ?float
    {
        $latest = $this->latestBid($lot);
        return $latest ? (float) $latest['amount'] : null;
    }

    private function firstLotId(): ?string
    {
        $lots = array_values($this->lots);
        usort($lots, fn ($a, $b) => $a['lot_number'] <=> $b['lot_number']);
        return $lots[0]['lot_id'] ?? null;
    }

    private function nextUpcomingLotId(): ?string
    {
        $lots = array_values($this->lots);
        usort($lots, fn ($a, $b) => $a['lot_number'] <=> $b['lot_number']);
        foreach ($lots as $lot) {
            if ($lot['state'] === 'upcoming') {
                return $lot['lot_id'];
            }
        }
        return null;
    }

    /**
     * @return array{customer_id: ?string, amount: float, paddle_id: mixed}|null
     */
    private function normaliseAdvance(mixed $advance): ?array
    {
        if (!is_array($advance) || !isset($advance['amount']) || !is_numeric($advance['amount'])) {
            return null;
        }
        return [
            'customer_id' => isset($advance['customer_id']) ? (string) $advance['customer_id'] : null,
            'amount' => $this->money($advance['amount']),
            'paddle_id' => $advance['paddle_id'] ?? null,
        ];
    }

    private static function plain(mixed $value): mixed
    {
        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('c');
            }
            if (method_exists($value, 'getArrayCopy')) {
                $value = $value->getArrayCopy();
            } elseif (method_exists($value, '__toString')) {
                return (string) $value;
            } else {
                $value = (array) $value;
            }
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::plain($item);
            }
        }
        return $value;
    }

    private function money(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new LiveSaleException('Amount must be a number');
        }
        return round((float) $value, 2);
    }

    private function greater(float $a, float $b): bool
    {
        return $a > $b + 0.001;
    }

    private function same(float $a, float $b): bool
    {
        return abs($a - $b) < 0.001;
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
