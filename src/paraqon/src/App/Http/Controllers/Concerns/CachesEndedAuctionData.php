<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Concerns;

use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

trait CachesEndedAuctionData
{
    protected function mongoCacheKey(string $suffix): string
    {
        $mongoDbName = config('database.connections.mongodb.database', 'default_database');

        return $mongoDbName . ':' . $suffix;
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
