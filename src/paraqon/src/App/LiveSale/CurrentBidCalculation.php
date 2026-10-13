<?php

namespace Starsnet\Project\Paraqon\App\LiveSale;

/**
 * Current price and winner for a lot.
 *
 * These are the cases in AuctionLot::getCurrentBidPrice and
 * getCurrentMaximumBidValue. AuctionLotController::createMaximumBid calls
 * getCurrentBidPrice after it has stored the bid. That method also creates a
 * bid history, so the live chant calls this class instead of the model.
 *
 * Bids are ordered here the same way getCurrentBidPrice loads them: highest
 * bid, then earlier created_at.
 * The winner is the first bid in that order. createMaximumBid's own winner
 * query sorts by bid only, which leaves an equal amount unordered.
 */
class CurrentBidCalculation
{
    /**
     * @param array<int, array{customer_id: mixed, bid: float|int, type: string, created_at: mixed}> $bids
     * @param array<int, mixed>|null $incrementRules
     * @param array{winning_bid_customer_id: mixed, current_bid: mixed}|null $lastHistory
     */
    public static function price(
        array $bids,
        mixed $startingPrice,
        mixed $reservePrice,
        ?array $incrementRules,
        mixed $newBidCustomerId,
        mixed $newBidValue,
        mixed $bidType,
        ?array $lastHistory
    ) {
        if (in_array($bidType, ['MAX', 'ADVANCED'])) {
            return self::maximumValue(
                $bids,
                $startingPrice,
                $reservePrice,
                $incrementRules,
                $newBidCustomerId,
                $newBidValue,
                $bidType,
                $lastHistory
            );
        }

        $maximumDirectBidValue = null;
        $maximumMaxBidValue = null;
        foreach (self::ordered($bids) as $bid) {
            if ($maximumDirectBidValue === null && ($bid['type'] ?? '') == 'DIRECT') {
                $maximumDirectBidValue = $bid['bid'];
            }
            if ($maximumMaxBidValue === null && in_array($bid['type'] ?? '', ['MAX', 'ADVANCED'])) {
                $maximumMaxBidValue = $bid['bid'];
            }
        }

        if (is_null($maximumDirectBidValue) && is_null($maximumMaxBidValue)) {
            return $startingPrice;
        }
        if (is_null($maximumMaxBidValue)) {
            return $maximumDirectBidValue;
        }
        if (!is_null($maximumDirectBidValue) && $maximumDirectBidValue > $maximumMaxBidValue) {
            return $maximumDirectBidValue; // Case 5
        }

        if (!is_null($maximumDirectBidValue) && $maximumDirectBidValue <= $maximumMaxBidValue && $lastHistory !== null) {
            if (($lastHistory['winning_bid_customer_id'] ?? null) == $newBidCustomerId) {
                return $maximumDirectBidValue; // Case 4A, 6A
            }
        }

        // Case 4B, 6B
        return self::maximumValue(
            $bids,
            $startingPrice,
            $reservePrice,
            $incrementRules,
            $newBidCustomerId,
            $newBidValue,
            $bidType,
            $lastHistory
        );
    }

    /**
     * @param array<int, array{customer_id: mixed, bid: float|int, type: string, created_at: mixed}> $bids
     * @param array<int, mixed>|null $incrementRules
     * @param array{winning_bid_customer_id: mixed, current_bid: mixed}|null $lastHistory
     */
    public static function maximumValue(
        array $bids,
        mixed $startingPrice,
        mixed $reservePrice,
        ?array $incrementRules,
        mixed $newBidCustomerId,
        mixed $newBidValue,
        mixed $bidType,
        ?array $lastHistory
    ) {
        // An ADVANCED bid skips Case 0.
        if (is_null($bidType) || $bidType != 'ADVANCED') {
            if (count($bids) > 2 && !is_null($newBidCustomerId) && $lastHistory !== null) {
                if (($lastHistory['winning_bid_customer_id'] ?? null) == $newBidCustomerId) {
                    $currentBid = $lastHistory['current_bid'];
                    if ($currentBid < $newBidValue && $newBidValue < $reservePrice) {
                        return $currentBid; // Case 0A
                    }
                    if ($newBidValue > $currentBid && $currentBid > $reservePrice) {
                        return $currentBid; // Case 0B
                    }
                    if ($newBidValue >= $reservePrice && $reservePrice > $currentBid) {
                        return $reservePrice; // Case 0C
                    }
                }
            }
        }

        $highest = [];
        foreach (self::ordered($bids) as $bid) {
            $key = self::customerKey($bid['customer_id'] ?? null);
            if (!array_key_exists($key, $highest)) {
                $highest[$key] = $bid;
            }
        }

        $count = count($highest);
        if ($count === 0) {
            return $startingPrice; // Case 1
        }

        $maxBidValue = max(array_column($highest, 'bid'));
        $reserveMet = $maxBidValue >= $reservePrice;
        if ($count === 1) {
            return $reserveMet ? $reservePrice : $startingPrice; // Case 3A, Case 2A
        }

        $tied = 0;
        foreach ($highest as $bid) {
            if ($bid['bid'] == $maxBidValue) {
                $tied++;
            }
        }
        if ($tied >= 2) {
            return $maxBidValue; // Case 2B (ii) & 3B (ii)
        }

        $orderedHighest = array_values($highest);
        $secondHighestBidValue = $orderedHighest[1]['bid'];
        $incrementalBid = 0;
        if (!is_null($incrementRules)) {
            foreach ($incrementRules as $interval) {
                $interval = (array) $interval;
                if ($secondHighestBidValue >= $interval['from'] && $secondHighestBidValue < $interval['to']) {
                    $incrementalBid = $interval['increment'];
                    break;
                }
            }
        }

        $stepped = min($maxBidValue, $secondHighestBidValue + $incrementalBid);
        if ($reserveMet) {
            return max($reservePrice, $stepped); // Case 3B (i)
        }
        return $stepped; // Case 2B (i)
    }

    /**
     * @param array<int, array{customer_id: mixed, bid: float|int, type?: string, created_at?: mixed}> $bids
     */
    public static function winnerId(array $bids): mixed
    {
        $ordered = self::ordered($bids);
        if ($ordered === []) {
            return null;
        }
        return $ordered[0]['customer_id'];
    }

    /**
     * @param iterable<mixed> $bids
     * @return array<int, array{customer_id: mixed, bid: mixed, type: string, created_at: mixed}>
     */
    public static function rowsFromBids(iterable $bids): array
    {
        $rows = [];
        foreach ($bids as $bid) {
            if (is_array($bid)) {
                $rows[] = [
                    'customer_id' => $bid['customer_id'] ?? null,
                    'bid' => $bid['bid'],
                    'type' => (string) ($bid['type'] ?? ''),
                    'created_at' => $bid['created_at'] ?? '',
                ];
                continue;
            }
            $rows[] = [
                'customer_id' => $bid->customer_id,
                'bid' => $bid->bid,
                'type' => (string) ($bid->type ?? ''),
                'created_at' => $bid->created_at,
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, mixed>|null
     */
    public static function incrementsFrom(mixed $settings): ?array
    {
        if ($settings === null) {
            return null;
        }
        if (is_array($settings) || $settings instanceof \ArrayAccess) {
            if (!isset($settings['increments'])) {
                return null;
            }
            $rules = $settings['increments'];
            return is_array($rules) ? $rules : (array) $rules;
        }
        if (is_object($settings) && isset($settings->increments)) {
            $rules = $settings->increments;
            return is_array($rules) ? $rules : (array) $rules;
        }
        return null;
    }

    /**
     * @param array<int, array{customer_id: mixed, bid: float|int, created_at?: mixed}> $bids
     * @return array<int, array{customer_id: mixed, bid: float|int, created_at?: mixed}>
     */
    private static function ordered(array $bids): array
    {
        $rows = array_values($bids);
        usort($rows, static function (array $left, array $right): int {
            if ($left['bid'] != $right['bid']) {
                return $left['bid'] < $right['bid'] ? 1 : -1;
            }
            return strcmp(self::timeKey($left['created_at'] ?? ''), self::timeKey($right['created_at'] ?? ''));
        });
        return $rows;
    }

    private static function timeKey(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return sprintf('%010d.%06d', (int) $value->format('U'), (int) $value->format('u'));
        }
        if (is_object($value) && method_exists($value, 'toDateTime')) {
            return self::timeKey($value->toDateTime());
        }
        return (string) $value;
    }

    private static function customerKey(mixed $customerId): string
    {
        if ($customerId === null) {
            return '';
        }
        return (string) $customerId;
    }
}
