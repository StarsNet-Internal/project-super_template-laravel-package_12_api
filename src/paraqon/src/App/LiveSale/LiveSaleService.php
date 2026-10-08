<?php

namespace Starsnet\Project\Paraqon\App\LiveSale;

use App\Enums\ReplyStatus;
use App\Enums\Status;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\LiveSaleEvent;
use Starsnet\Project\Paraqon\App\Models\LiveSaleState;

/**
 * Persists the live sale book and tells the consoles.
 *
 * Lot commands do not write bid_histories, and they do not change the
 * auction lot fields the online change-stream listener watches. Start and
 * end update only the store status, and only for a LIVE store, so the
 * existing live-auction emails still go out.
 */
class LiveSaleService
{
    public function snapshot(string $storeId, bool $clerk, ?string $customerId): array
    {
        $this->requireLiveStore($storeId);
        $doc = LiveSaleState::where('store_id', $storeId)->first();
        $book = null;
        if ($doc) {
            $book = $this->bookFromDocument($doc);
            if ($book->status() === 'idle') {
                $book = null;
            }
        }
        if ($book === null) {
            $book = LiveSaleBook::empty($storeId);
            try {
                $book->loadPreview($this->catalogue($storeId));
            } catch (LiveSaleException $e) {
                // An empty catalogue still returns a blank book.
            }
        }
        return $this->present($book, gmdate('c'), $clerk, $customerId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(
        string $storeId,
        string $command,
        array $payload,
        ?string $actorId,
        bool $clerk,
        ?string $customerId = null
    ): array {
        $store = $this->requireLiveStore($storeId);
        $this->ensure($storeId);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $doc = LiveSaleState::where('store_id', $storeId)->first();
            if ($doc === null) {
                $this->ensure($storeId);
                continue;
            }
            $expected = (int) ($doc->sequence ?? 0);
            $book = $this->bookFromDocument($doc);

            if ($command === 'start_sale' && $book->status() === 'running') {
                $at = gmdate('c');
                $this->publish($storeId, $book, $at);
                return $this->present($book, $at, $clerk, $customerId);
            }

            $ready = $payload;
            try {
                $ready = $this->preparePayload($storeId, $command, $payload, $customerId);
                if ($command === 'open_lot') {
                    $lotId = (string) ($ready['lot_id'] ?? $book->currentLotId() ?? '');
                    if ($lotId !== '') {
                        $ready['advances'] = $this->advancesForLot($storeId, $lotId);
                    }
                }
                $summary = $book->apply($command, $ready);
            } catch (LiveSaleException $e) {
                $this->recordRejection($storeId, $command, $ready ?? $payload, $actorId, $e->getMessage(), $book);
                abort(422, $e->getMessage());
            }

            if (!$this->commit($storeId, $expected, $book)) {
                continue;
            }

            $this->record($storeId, $summary, $actorId);
            $this->markStoreFor($store, $command);
            $this->publish($storeId, $book, $summary['at']);
            return $this->present($book, $summary['at'], $clerk, $customerId);
        }

        abort(409, 'The sale changed. Try again');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function preparePayload(string $storeId, string $command, array $payload, ?string $customerId): array
    {
        unset($payload['at'], $payload['lots'], $payload['command']);

        if ($command === 'start_sale') {
            $payload['lots'] = $this->catalogue($storeId);
            return $payload;
        }

        if ($command === 'submit_online_bid') {
            $registration = $this->registrationForCustomer($storeId, (string) $customerId);
            if ($registration === null || $registration->paddle_id === null || $registration->paddle_id === '') {
                throw new LiveSaleException('Register for a paddle before bidding');
            }
            $payload['customer_id'] = (string) $registration->requested_by_customer_id;
            $payload['paddle_id'] = $registration->paddle_id;
            return $payload;
        }

        if ($command === 'accept_bid') {
            $source = (string) ($payload['source'] ?? '');
            if ($source === 'house') {
                $payload['customer_id'] = null;
                $payload['paddle_id'] = null;
                return $payload;
            }
            if (in_array($source, ['floor', 'phone'], true)) {
                $paddleId = $payload['paddle_id'] ?? null;
                if ($paddleId !== null && $paddleId !== '') {
                    $registration = $this->registrationForPaddle($storeId, $paddleId);
                    if ($registration === null) {
                        throw new LiveSaleException('That paddle is not registered for this sale');
                    }
                    $payload['customer_id'] = (string) $registration->requested_by_customer_id;
                    $payload['paddle_id'] = $registration->paddle_id;
                }
            }
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(string $storeId): array
    {
        $lots = AuctionLot::where('store_id', $storeId)
            ->where('status', '!=', Status::DELETED->value)
            ->whereNotNull('lot_number')
            ->get();

        $lotIds = [];
        foreach ($lots as $lot) {
            $lotIds[(string) $lot->_id] = true;
        }
        $paddles = $this->paddleByCustomer($storeId);
        $advances = $this->advances($storeId, $lotIds, $paddles);

        $catalogue = [];
        foreach ($lots as $lot) {
            $lotId = (string) $lot->_id;
            $title = $this->plain($lot->title);
            if (!is_array($title)) {
                $title = ['en' => is_string($title) ? $title : '', 'zh' => '', 'cn' => ''];
            }
            $requests = $this->plain($lot->permission_requests);
            $catalogue[] = [
                'lot_id' => $lotId,
                'lot_number' => (int) $lot->lot_number,
                'product_id' => $lot->product_id ? (string) $lot->product_id : null,
                'title' => $title,
                'starting_price' => $lot->starting_price,
                'reserve_price' => $lot->reserve_price,
                'bid_incremental_settings' => $this->plain($lot->bid_incremental_settings),
                'is_permission_required' => (bool) $lot->is_permission_required,
                'permission_requests' => is_array($requests) ? array_values($requests) : [],
                'created_at' => $this->instant($lot->created_at),
                'advances' => $advances[$lotId] ?? [],
            ];
        }
        return $catalogue;
    }

    /**
     * @param array<string, bool> $lotIds
     * @param array<string, mixed> $paddles
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function advances(string $storeId, array $lotIds, array $paddles): array
    {
        $bids = Bid::where('store_id', $storeId)
            ->where('type', 'ADVANCED')
            ->get()
            ->filter(function ($bid) use ($lotIds) {
                if (!isset($lotIds[(string) $bid->auction_lot_id])) {
                    return false;
                }
                $hidden = $bid->is_hidden;
                return $hidden === false || $hidden === 0 || $hidden === '0' || $hidden === null;
            })
            ->sortBy(function ($bid) {
                $created = $bid->created_at;
                if ($created instanceof \DateTimeInterface) {
                    return $created->getTimestamp();
                }
                return is_string($created) ? strtotime($created) : 0;
            });

        $byLot = [];
        foreach ($bids as $bid) {
            $lotId = (string) $bid->auction_lot_id;
            $customerId = (string) $bid->customer_id;
            $amount = (float) $bid->bid;
            $placedAt = $this->instant($bid->created_at) ?? '';
            $row = [
                'customer_id' => $customerId,
                'amount' => $amount,
                'paddle_id' => $paddles[$customerId] ?? null,
                'placed_at' => $placedAt,
            ];
            if (!isset($byLot[$lotId])) {
                $byLot[$lotId] = [];
            }
            $existing = null;
            foreach ($byLot[$lotId] as $index => $advance) {
                if ($advance['customer_id'] === $customerId) {
                    $existing = $index;
                    break;
                }
            }
            if ($existing === null) {
                $byLot[$lotId][] = $row;
                continue;
            }
            if ($amount > $byLot[$lotId][$existing]['amount'] + 0.001) {
                $byLot[$lotId][$existing]['amount'] = $amount;
            }
        }
        return $byLot;
    }

    /**
     * Advances placed or raised before the lot opens. Opening the lot copies this list onto the book.
     *
     * @return array<int, array<string, mixed>>
     */
    private function advancesForLot(string $storeId, string $lotId): array
    {
        $byLot = $this->advances($storeId, [$lotId => true], $this->paddleByCustomer($storeId));
        return $byLot[$lotId] ?? [];
    }

    /**
     * An absentee can change until the lot is opened. After that the book copy is the one that runs.
     */
    public function assertAdvanceEditable(string $storeId, string $lotId): void
    {
        $doc = LiveSaleState::where('store_id', $storeId)->first();
        if ($doc === null) {
            return;
        }
        $book = $this->bookFromDocument($doc);
        if ($book->status() !== 'running') {
            return;
        }
        $state = $book->lotState($lotId);
        if ($state === null || in_array($state, ['upcoming', 'preparing'], true)) {
            return;
        }
        abort(422, 'This lot is open. The absentee bid can no longer be changed');
    }

    /**
     * @return array<string, mixed>
     */
    private function paddleByCustomer(string $storeId): array
    {
        $map = [];
        foreach ($this->approvedRegistrations($storeId) as $registration) {
            $map[(string) $registration->requested_by_customer_id] = $registration->paddle_id;
        }
        return $map;
    }

    private function registrationForCustomer(string $storeId, string $customerId): ?AuctionRegistrationRequest
    {
        if ($customerId === '') {
            return null;
        }
        foreach ($this->approvedRegistrations($storeId) as $registration) {
            if ((string) $registration->requested_by_customer_id === $customerId && $registration->paddle_id !== null && $registration->paddle_id !== '') {
                return $registration;
            }
        }
        return null;
    }

    private function registrationForPaddle(string $storeId, mixed $paddleId): ?AuctionRegistrationRequest
    {
        foreach ($this->approvedRegistrations($storeId) as $registration) {
            if ((string) $registration->paddle_id === (string) $paddleId) {
                return $registration;
            }
        }
        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, AuctionRegistrationRequest>
     */
    private function approvedRegistrations(string $storeId)
    {
        return AuctionRegistrationRequest::where('store_id', $storeId)
            ->where('reply_status', ReplyStatus::APPROVED->value)
            ->get();
    }

    private function requireLiveStore(string $storeId): Store
    {
        /** @var ?Store $store */
        $store = Store::find($storeId);
        if ($store === null) {
            abort(404, 'Store not found');
        }
        if (($store->auction_type ?? null) !== 'LIVE') {
            abort(422, 'This sale is not a live auction');
        }
        return $store;
    }

    private function ensure(string $storeId): void
    {
        $empty = LiveSaleBook::empty($storeId)->toArray();
        LiveSaleState::raw(function ($collection) use ($storeId, $empty) {
            $collection->updateOne(
                ['store_id' => $storeId],
                ['$setOnInsert' => [
                    'store_id' => $storeId,
                    'sequence' => 0,
                    'book' => $empty,
                ]],
                ['upsert' => true]
            );
        });
    }

    private function bookFromDocument(LiveSaleState $doc): LiveSaleBook
    {
        $raw = $this->asArray($doc->book);
        $raw['store_id'] = (string) ($raw['store_id'] ?? $doc->store_id);
        $raw['sequence'] = (int) ($doc->sequence ?? ($raw['sequence'] ?? 0));
        return LiveSaleBook::fromArray($raw);
    }

    private function commit(string $storeId, int $expected, LiveSaleBook $book): bool
    {
        $result = LiveSaleState::raw(function ($collection) use ($storeId, $expected, $book) {
            return $collection->updateOne(
                ['store_id' => $storeId, 'sequence' => $expected],
                ['$set' => [
                    'sequence' => $book->sequence(),
                    'book' => $book->toArray(),
                ]]
            );
        });
        return $result->getMatchedCount() === 1;
    }

    /**
     * @param array<string, mixed> $summary
     */
    /**
     * A rejected bid does not change the book. The row names the clerk and the reason.
     *
     * @param array<string, mixed> $payload
     */
    private function recordRejection(
        string $storeId,
        string $command,
        array $payload,
        ?string $actorId,
        string $message,
        LiveSaleBook $book
    ): void {
        try {
            LiveSaleEvent::create([
                'store_id' => $storeId,
                'sequence' => $book->sequence(),
                'command' => 'rejected',
                'actor_id' => $actorId,
                'at' => gmdate('c'),
                'lot_id' => $payload['lot_id'] ?? $book->currentLotId(),
                'summary' => [
                    'command' => $command,
                    'message' => $message,
                    'actor_id' => $actorId,
                    'amount' => $payload['amount'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Live sale rejection was not recorded', [
                'store_id' => $storeId,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Orders and deposits use the ended book. Passed lots have no buyer.
     * A sale that was reopened and then undone contributes only the final stack.
     *
     * @return array{results: array<int, array<string, mixed>>, lots: array<int, array<string, mixed>>}|null
     */
    public function settlementForOrders(string $storeId): ?array
    {
        $doc = LiveSaleState::where('store_id', $storeId)->first();
        if ($doc === null) {
            return null;
        }
        $book = $this->bookFromDocument($doc);
        if ($book->status() !== 'ended') {
            return null;
        }
        $lots = $book->settlement();
        $grouped = [];
        foreach ($lots as $lot) {
            if ($lot['result'] !== 'sold') {
                continue;
            }
            $customerId = $lot['customer_id'] ?? null;
            if ($customerId === null || $customerId === '') {
                continue;
            }
            $customerId = (string) $customerId;
            if (!isset($grouped[$customerId])) {
                $grouped[$customerId] = [
                    'customer_id' => $customerId,
                    'lots' => [],
                ];
            }
            $grouped[$customerId]['lots'][] = [
                'lot_id' => $lot['lot_id'],
                'price' => $lot['hammer_price'],
                'sold_price' => $lot['hammer_price'],
            ];
        }
        return [
            'results' => array_values($grouped),
            'lots' => $lots,
        ];
    }

    private function record(string $storeId, array $summary, ?string $actorId): void
    {
        LiveSaleEvent::create([
            'store_id' => $storeId,
            'sequence' => $summary['sequence'],
            'command' => $summary['command'],
            'actor_id' => $actorId,
            'at' => $summary['at'],
            'lot_id' => $summary['lot_id'] ?? null,
            'summary' => $summary,
        ]);
    }

    private function markStoreFor(Store $store, string $command): void
    {
        $status = match ($command) {
            'start_sale' => Status::ACTIVE->value,
            'end_sale' => Status::ARCHIVED->value,
            default => null,
        };
        if ($status === null || $store->status === $status) {
            return;
        }
        try {
            $store->status = $status;
            $store->save();
        } catch (\Throwable $e) {
            Log::error('Live sale store status was not updated', [
                'store_id' => (string) $store->_id,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function publish(string $storeId, LiveSaleBook $book, string $at): void
    {
        $url = env('PARAQON_SOCKET_BASE_URL', 'https://socket.paraqon.starsnet.hk') . '/api/publish';
        $messages = [
            ['room' => 'live-' . $storeId, 'data' => $book->publicPayload($at)],
            ['room' => 'live-' . $storeId . '-clerk', 'data' => $book->clerkPayload($at)],
        ];
        foreach ($messages as $message) {
            try {
                $response = Http::timeout(3)->post($url, [
                    'site' => 'paraqon',
                    'room' => $message['room'],
                    'data' => $message['data'],
                    'event' => 'liveBidding',
                ]);
                if (!$response->successful()) {
                    Log::error('Live sale publish failed', [
                        'store_id' => $storeId,
                        'room' => $message['room'],
                        'sequence' => $book->sequence(),
                        'status' => $response->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Live sale publish failed', [
                    'store_id' => $storeId,
                    'room' => $message['room'],
                    'sequence' => $book->sequence(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LiveSaleBook $book, string $at, bool $clerk, ?string $customerId): array
    {
        if ($clerk) {
            return $book->clerkPayload($at);
        }
        $payload = $book->publicPayload($at);
        if ($customerId !== null && $customerId !== '') {
            $payload['you'] = $book->viewer($customerId);
            $payload = $book->forViewer($payload, $customerId);
        }
        $saved = [];
        foreach ($book->toArray()['lots'] as $lot) {
            $requests = $lot['permission_requests'] ?? [];
            if (!is_array($requests)) {
                $requests = [];
            }
            if ($customerId) {
                $requests = array_values(array_filter($requests, function ($item) use ($customerId) {
                    $item = (array) $item;
                    return (string) ($item['customer_id'] ?? '') === $customerId;
                }));
            } else {
                $requests = [];
            }
            $saved[(string) $lot['lot_id']] = $requests;
        }
        foreach ($payload['lots'] as $index => $row) {
            $payload['lots'][$index]['permission_requests'] = $saved[(string) $row['_id']] ?? [];
        }
        return $payload;
    }

    private function plain(mixed $value): mixed
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
                $value[$key] = $this->plain($item);
            }
        }
        return $value;
    }

    private function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            $copy = $value->getArrayCopy();
            return is_array($copy) ? $copy : [];
        }
        if (is_object($value)) {
            return (array) $value;
        }
        return [];
    }

    private function instant(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_object($value) && method_exists($value, 'toDateTime')) {
            return $value->toDateTime()->format('c');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }
}
