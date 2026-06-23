<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Concerns;

use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

trait CachesEndedAuctionData
{
    /**
     * Max bytes per Redis value. A serialized payload larger than this is
     * split across multiple keys so it never exceeds Redis' per-key limit.
     */
    protected int $cacheChunkBytes = 4194304; // 4 MB

    protected function mongoCacheKey(string $suffix): string
    {
        $mongoDbName = config('database.connections.mongodb.database', 'default_database');

        return $mongoDbName . ':' . $suffix;
    }

    /**
     * Only cache when the related auction/lot has ended (ended data is final and
     * safe to serve forever). While still live, always recompute.
     *
     * @template T
     * @param  callable(): T  $compute
     * @return T
     */
    protected function rememberIfEnded(bool $isEnded, string $cacheKey, callable $compute)
    {
        if (!$isEnded) {
            return $compute();
        }

        $cached = $this->getChunkedFromCache($cacheKey);
        if (!is_null($cached)) {
            return $cached['value'];
        }

        $value = $compute();
        $this->putChunkedToCache($cacheKey, $value);

        return $value;
    }

    /**
     * Serialize and store a value forever, splitting it into byte-bounded chunks
     * when it is too large for a single Redis key. A manifest key records how
     * many chunks to read back.
     */
    protected function putChunkedToCache(string $key, mixed $value): void
    {
        $store = Cache::store('redis');
        $serialized = serialize($value);

        if (strlen($serialized) <= $this->cacheChunkBytes) {
            $store->forever($key . ':manifest', ['chunked' => false, 'data' => $serialized]);
            return;
        }

        $chunks = str_split($serialized, $this->cacheChunkBytes);
        foreach ($chunks as $index => $chunk) {
            $store->forever($key . ':chunk:' . $index, $chunk);
        }
        $store->forever($key . ':manifest', ['chunked' => true, 'count' => count($chunks)]);
    }

    /**
     * Read back a chunked value. Returns null on a cache miss, or
     * ['value' => mixed] on a hit (so a cached null is distinguishable).
     *
     * @return array{value: mixed}|null
     */
    protected function getChunkedFromCache(string $key): ?array
    {
        $store = Cache::store('redis');

        $manifest = $store->get($key . ':manifest');
        if (!is_array($manifest) || !array_key_exists('chunked', $manifest)) {
            return null;
        }

        if ($manifest['chunked'] === false) {
            return ['value' => unserialize($manifest['data'])];
        }

        $serialized = '';
        for ($index = 0; $index < $manifest['count']; $index++) {
            $chunk = $store->get($key . ':chunk:' . $index);
            // A missing/expired chunk means the payload is incomplete; treat as a miss.
            if (is_null($chunk)) {
                return null;
            }
            $serialized .= $chunk;
        }

        return ['value' => unserialize($serialized)];
    }

    protected function isStoreEnded(?Store $store): bool
    {
        if (is_null($store)) {
            return false;
        }

        $storeEndDatetime = $store->end_datetime ?? null;

        return $storeEndDatetime !== null && Carbon::parse($storeEndDatetime)->isPast();
    }

    protected function isAuctionLotEnded(AuctionLot $auctionLot): bool
    {
        $endDatetime = $auctionLot->end_datetime
            ?? optional($auctionLot->store)->end_datetime
            ?? null;

        return $endDatetime !== null && Carbon::parse($endDatetime)->isPast();
    }

    /**
     * @param Collection<int, Store> $endedFromDb
     * @return Collection<int, Store>
     */
    protected function rememberEndedStores(string $cacheKey, Collection $endedFromDb): Collection
    {
        /** @var Collection<int, Store> $cachedEnded */
        $cachedEnded = Cache::store('redis')->get($cacheKey, new Collection());

        $mergedEnded = $cachedEnded
            ->keyBy(fn(Store $store) => (string) $store->id)
            ->merge($endedFromDb->keyBy(fn(Store $store) => (string) $store->id))
            ->values();

        Cache::store('redis')->forever($cacheKey, $mergedEnded);

        return $mergedEnded;
    }

    /**
     * @param Collection<int, AuctionLot> $endedFromDb
     * @return Collection<int, AuctionLot>
     */
    protected function rememberEndedAuctionLots(string $cacheKey, Collection $endedFromDb): Collection
    {
        /** @var Collection<int, AuctionLot> $cachedEnded */
        $cachedEnded = Cache::store('redis')->get($cacheKey, new Collection());

        $mergedEnded = $cachedEnded
            ->keyBy(fn(AuctionLot $lot) => (string) $lot->id)
            ->merge($endedFromDb->keyBy(fn(AuctionLot $lot) => (string) $lot->id))
            ->values();

        Cache::store('redis')->forever($cacheKey, $mergedEnded);

        return $mergedEnded;
    }
}
