<?php

namespace Starsnet\Project\Paraqon\App\Services;

use App\Enums\Status;
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Document;
use StarsNet\Project\Paraqon\App\Models\LocationHistory;

class AccountItemService
{
    public const SCOPES = ['selling', 'sold', 'purchased'];
    public const PURPOSES = ['AUCTION', 'PRIVATE_SALE', 'THE_VAULT'];

    private const SELLER_DOCUMENT_TYPES = [
        'CONSIGNMENT_AGREEMENT_FOR_AUCTION',
        'PRIVATE_SALE_AGREEMENT',
        'CONSIGNOR_SETTLEMENT',
    ];

    private const LOCATION_IN_OFFICE = 'IN_OFFICE';
    private const STATUS_RETURNED_TO_CONSIGNOR = 'RETURNED_TO_CONSIGNOR';
    private const SELLER_DELIVERED_STATUSES = [
        'SOLD',
        'SOLD_ORDER_COMPLETED',
        'PACKAGE_HAS_SHIPPED',
    ];
    private const STATUS_PACKAGE_HAS_SHIPPED = 'PACKAGE_HAS_SHIPPED';

    public function getAll(
        string $customerID,
        string $scope,
        ?string $purpose = null
    ): Collection {
        $stores = $this->getStoreContext();
        $documents = $scope === 'purchased'
            ? collect()
            : $this->getSellerDocuments($customerID);

        if ($scope === 'purchased') {
            $orders = $this->getCustomerOrders(
                $customerID,
                $stores['relevant_ids'],
                $stores['auction_ids']
            );
            $productIDs = $this->getProductIDsFromOrders($orders);
            $products = $this->getProducts($productIDs);
        } else {
            $productIDs = $this->getSellerProductIDs($customerID, $documents);
            $products = $this->getProducts($productIDs);
            if ($scope === 'selling') {
                $products = $products
                    ->where('status', '!=', Status::DRAFT->value)
                    ->values();
                $productIDs = $products
                    ->map(fn(Product $product) => (string) $product->_id)
                    ->all();
            }
            $orders = $this->getOrdersForProducts(
                $productIDs,
                $stores['relevant_ids'],
                $stores['auction_ids']
            );
        }

        $context = $this->buildContext(
            $products,
            $productIDs,
            $orders,
            $documents,
            $stores
        );

        $items = match ($scope) {
            'purchased' => $this->buildPurchasedItems($orders, $context),
            'sold' => $this->buildSoldItems($products, $context),
            default => $this->buildSellingItems($products, $context),
        };

        if (!is_null($purpose)) {
            $items = $items->where('purpose', $purpose);
        }

        return $items->values();
    }

    private function getStoreContext(): array
    {
        $mainStore = $this->getStoreByAliasOrSlug('default-main-store');
        $privateSaleStore = $this->getStoreByAliasOrSlug('private-sale-store');
        $auctionStores = Store::whereNotNull('auction_type')->get();
        $auctionStoreIDs = $auctionStores
            ->map(fn(Store $store) => (string) $store->_id)
            ->all();

        $relevantStores = collect($auctionStores->all())
            ->when($mainStore, fn(Collection $items) => $items->push($mainStore))
            ->when($privateSaleStore, fn(Collection $items) => $items->push($privateSaleStore))
            ->unique(fn(Store $store) => (string) $store->_id)
            ->values();

        $vaultCategoryIDs = is_null($mainStore)
            ? collect()
            : ProductCategory::where('model_type_id', $mainStore->id)
                ->statusActive()
                ->pluck('id')
                ->map(fn($id) => (string) $id)
                ->values();

        return [
            'main_id' => is_null($mainStore) ? null : (string) $mainStore->_id,
            'auction_ids' => $auctionStoreIDs,
            'vault_category_ids' => $vaultCategoryIDs,
            'relevant_ids' => $relevantStores
                ->map(fn(Store $store) => (string) $store->_id)
                ->all(),
            'by_id' => $relevantStores->keyBy(fn(Store $store) => (string) $store->_id),
        ];
    }

    private function getStoreByAliasOrSlug(string $alias): ?Store
    {
        $storeID = Alias::getValue($alias);

        return (!is_null($storeID) ? Store::find($storeID) : null)
            ?? Store::where('slug', $alias)->latest()->first();
    }

    private function getSellerDocuments(string $customerID): Collection
    {
        return Document::where('customer_id', $customerID)
            ->whereIn('type', self::SELLER_DOCUMENT_TYPES)
            ->where('status', '!=', Status::DELETED->value)
            ->get();
    }

    private function getSellerProductIDs(
        string $customerID,
        Collection $documents
    ): array {
        $productIDs = Product::where('status', '!=', Status::DELETED->value)
            ->where(function ($query) use ($customerID) {
                $query->where('seller_id', $customerID)
                    ->orWhere(function ($legacyQuery) use ($customerID) {
                        $legacyQuery->whereNull('seller_id')
                            ->where('owned_by_customer_id', $customerID)
                            ->whereIn('listing_status', ['AVAILABLE', 'PENDING_FOR_AUCTION']);
                    });
            })
            ->pluck('id');

        $auctionProductIDs = AuctionLot::where('owned_by_customer_id', $customerID)
            ->where('status', '!=', Status::DELETED->value)
            ->pluck('product_id');

        $documentProductIDs = $documents->flatMap(
            fn(Document $document) => collect($document->items ?? [])->pluck('product_id')
        );

        return $productIDs
            ->concat($auctionProductIDs)
            ->concat($documentProductIDs)
            ->filter()
            ->map(fn($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function getProducts(array $productIDs): Collection
    {
        if (count($productIDs) === 0) return collect();

        return Product::whereIn('_id', $productIDs)
            ->where('status', '!=', Status::DELETED->value)
            ->get();
    }

    private function getCustomerOrders(
        string $customerID,
        array $relevantStoreIDs,
        array $auctionStoreIDs
    ): Collection {
        if (count($relevantStoreIDs) === 0) return collect();

        $orders = Order::where('customer_id', $customerID)
            ->whereIn('store_id', $relevantStoreIDs)
            ->get();

        return $this->collapseAuctionOrderGroups($orders, $auctionStoreIDs);
    }

    /**
     * Mirror the Admin Auction Orders pairing rule. Auction checkout keeps a
     * system order plus one or more non-system payment attempts without a
     * reliable paid_order_id relation. Admin pairs them by store and customer,
     * ignores unpaid online attempts, and uses the latest eligible non-system
     * order; otherwise it keeps the latest system order.
     *
     * Do not apply this grouping to The Vault or Private Sale because repeated
     * orders in those stores are independent purchases.
     */
    private function collapseAuctionOrderGroups(
        Collection $orders,
        array $auctionStoreIDs
    ): Collection {
        $auctionStoreIDs = collect($auctionStoreIDs)
            ->map(fn($id) => (string) $id);
        [$auctionOrders, $otherOrders] = $orders->partition(
            fn(Order $order) => $auctionStoreIDs->contains(
                (string) $order->store_id
            )
        );

        $selectedAuctionOrders = $auctionOrders
            ->groupBy(
                fn(Order $order) =>
                    (string) $order->store_id
                    . ':'
                    . (string) $order->customer_id
            )
            ->map(function (Collection $group): ?Order {
                $latestManualOrder = $group
                    ->filter(fn(Order $order) => !(bool) $order->is_system)
                    ->reject(
                        fn(Order $order) =>
                            $order->payment_method === 'ONLINE'
                            && !(bool) $order->is_paid
                    )
                    ->sortByDesc('created_at')
                    ->first();

                if (!is_null($latestManualOrder)) {
                    return $latestManualOrder;
                }

                return $group
                    ->filter(fn(Order $order) => (bool) $order->is_system)
                    ->sortByDesc('created_at')
                    ->first();
            })
            ->filter();

        return $otherOrders
            ->concat($selectedAuctionOrders)
            ->values();
    }

    private function getOrdersForProducts(
        array $productIDs,
        array $relevantStoreIDs,
        array $auctionStoreIDs
    ): Collection {
        if (count($productIDs) === 0 || count($relevantStoreIDs) === 0) {
            return collect();
        }

        $orders = Order::whereIn('store_id', $relevantStoreIDs)
            ->whereIn('cart_items.product_id', $productIDs)
            ->get();

        return $this->collapseAuctionOrderGroups($orders, $auctionStoreIDs);
    }

    private function getProductIDsFromOrders(Collection $orders): array
    {
        return $orders
            ->flatMap(fn(Order $order) => collect($order->cart_items)->pluck('product_id'))
            ->filter()
            ->map(fn($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function buildContext(
        Collection $products,
        array $productIDs,
        Collection $orders,
        Collection $documents,
        array $stores
    ): array {
        $orderIDs = $orders
            ->map(fn(Order $order) => (string) $order->_id)
            ->all();

        $variants = count($productIDs) === 0
            ? collect()
            : ProductVariant::whereIn('product_id', $productIDs)->get();
        $lots = count($productIDs) === 0
            ? collect()
            : AuctionLot::whereIn('product_id', $productIDs)
                ->where('status', '!=', Status::DELETED->value)
                ->get();
        $histories = count($productIDs) === 0
            ? collect()
            : LocationHistory::whereIn('product_id', $productIDs)->get();
        $checkouts = count($orderIDs) === 0
            ? collect()
            : Checkout::whereIn('order_id', $orderIDs)->get();

        return [
            'products_by_id' => $products->keyBy(fn(Product $product) => (string) $product->_id),
            'variants_by_product' => $variants
                ->groupBy(fn(ProductVariant $variant) => (string) $variant->product_id)
                ->map(fn(Collection $items) => $this->latest($items)),
            'lots_by_product' => $lots
                ->groupBy(fn(AuctionLot $lot) => (string) $lot->product_id)
                ->map(fn(Collection $items) => $this->latestFirst($items)),
            'histories_by_product' => $histories
                ->groupBy(fn(LocationHistory $history) => (string) $history->product_id),
            'orders_by_product' => $this->groupOrdersByProduct($orders),
            'documents_by_product' => $this->groupDocumentsByProduct($documents),
            'checkouts_by_order' => $checkouts
                ->groupBy(fn(Checkout $checkout) => (string) $checkout->order_id)
                ->map(fn(Collection $items) => $this->latest($items)),
            'stores' => $stores,
        ];
    }

    private function buildSellingItems(
        Collection $products,
        array $context
    ): Collection {
        return $products
            ->filter(function (Product $product) use ($context) {
                $productID = (string) $product->_id;
                $lot = $context['lots_by_product']->get($productID)?->first();

                return is_null($lot) || $lot->status !== Status::DRAFT->value;
            })
            ->map(function (Product $product) use ($context) {
                $productID = (string) $product->_id;
                $orderEntry = $context['orders_by_product']->get($productID)?->first();

                return $this->buildItem(
                    $product,
                    $orderEntry['order'] ?? null,
                    $orderEntry['item'] ?? null,
                    'sold',
                    $context,
                    formatSellingColumns: true
                );
            });
    }

    private function buildSoldItems(
        Collection $products,
        array $context
    ): Collection {
        return $products
            ->filter(function (Product $product) use ($context) {
                $productID = (string) $product->_id;
                $hasOrder = $context['orders_by_product']->has($productID);
                $hasSettlement = $context['documents_by_product']
                    ->get($productID, collect())
                    ->contains(fn(array $entry) => $entry['document']->type === 'CONSIGNOR_SETTLEMENT');

                return $hasOrder || $hasSettlement;
            })
            ->map(function (Product $product) use ($context) {
                $productID = (string) $product->_id;
                $orderEntry = $context['orders_by_product']->get($productID)?->first();

                return $this->buildItem(
                    $product,
                    $orderEntry['order'] ?? null,
                    $orderEntry['item'] ?? null,
                    'sold',
                    $context,
                    includeSettlement: true,
                    useOrderPurpose: true
                );
            });
    }

    private function buildPurchasedItems(
        Collection $orders,
        array $context
    ): Collection {
        return $orders
            ->reject(
                fn(Order $order) =>
                    !is_null($context['stores']['main_id'])
                    && (string) $order->store_id === $context['stores']['main_id']
                    && $order->payment_method === 'ONLINE'
                    && !(bool) $order->is_paid
            )
            ->flatMap(function (Order $order) use ($context) {
                return collect($order->cart_items)
                    ->filter(fn($orderItem) => !empty(data_get($orderItem, 'product_id')))
                    ->map(function ($orderItem) use ($order, $context) {
                        $productID = (string) data_get($orderItem, 'product_id');
                        $product = $context['products_by_id']->get($productID);

                        return $this->buildItem(
                            $product,
                            $order,
                            $orderItem,
                            'buy',
                            $context,
                            useOrderPurpose: true
                        );
                    });
            });
    }

    private function buildItem(
        ?Product $product,
        ?Order $order,
        $orderItem,
        string $channel,
        array $context,
        bool $includeSettlement = false,
        bool $useOrderPurpose = false,
        bool $formatSellingColumns = false
    ): array {
        $productID = (string) ($product?->_id ?? data_get($orderItem, 'product_id', ''));
        $lots = $context['lots_by_product']->get($productID, collect());
        $lot = $this->getMatchingLot($lots, $order);
        $store = is_null($order)
            ? $context['stores']['by_id']->get((string) ($lot?->store_id ?? ''))
            : $context['stores']['by_id']->get((string) $order->store_id);
        $purpose = $this->getPurpose(
            $product,
            $order,
            $lot,
            $store,
            $context['stores'],
            $useOrderPurpose
        );
        $variant = $context['variants_by_product']->get($productID);
        $histories = $context['histories_by_product']->get($productID, collect());
        $latestHistory = $this->latest($histories);
        $documents = $context['documents_by_product']->get($productID, collect());
        $agreement = $this->getAgreement($documents, $purpose);
        $settlement = $includeSettlement
            ? $this->getDocumentEntry($documents, 'CONSIGNOR_SETTLEMENT')
            : null;
        $checkout = is_null($order)
            ? null
            : $context['checkouts_by_order']->get((string) $order->_id);

        $price = $purpose === 'AUCTION'
            ? $this->number($lot?->reserve_price)
            : ($this->number($variant?->price)
                ?? $this->number(
                    data_get($orderItem, 'original_price_per_unit')
                        ?? data_get($orderItem, 'discounted_price_per_unit')
                ));
        $hammerPrice = $this->getHammerPrice($orderItem, $purpose);
        $commission = $this->number(data_get($orderItem, 'commission')) ?? 0.0;
        $total = $this->getPurchasedTotal($orderItem, $hammerPrice, $commission);
        $otherFees = !is_null($total) && !is_null($hammerPrice)
            ? max(0, $total - $hammerPrice - $commission)
            : null;

        $settlementItem = $settlement['item'] ?? null;
        $settlementDocument = $settlement['document'] ?? null;
        $hasSettlement = !is_null($settlementItem);
        $paymentStatus = $includeSettlement && $hasSettlement
            ? $this->getSettlementPaymentStatus($settlementDocument)
            : $this->getOrderPaymentStatus($order, $checkout);
        $paymentTimestampSource = $includeSettlement && $hasSettlement
            ? $settlementDocument
            : $checkout;
        $paymentReceipt = $includeSettlement
            ? $this->getSettlementPaymentReceipt($settlementDocument)
            : (
                $channel === 'buy'
                    && !is_null($order)
                    && (bool) $order->is_paid
                ? data_get($order, 'documents.payment_receipt')
                : null
            );
        $agreementReference = data_get($agreement, 'document.statement_no');
        $agreementContractReference = $this->getAgreementContractReference(
            $agreement
        );
        $settlementReference = $settlementDocument?->reference_no;
        if (empty($settlementReference)) {
            $settlementReference = $settlementDocument?->statement_no;
        }

        $stockNumber = $this->getStockNumber($product, $orderItem);
        $auctionPrefix = $purpose === 'AUCTION'
            ? $this->getAuctionPrefix($store)
            : null;
        $lotNumber = $purpose === 'AUCTION'
            ? $this->getLotNumber($lot, $orderItem)
            : null;

        return [
            '_id' => $productID,
            'order_id' => is_null($order) ? null : (string) $order->_id,
            'stock_no' => $this->getDisplayStockNumber(
                $stockNumber,
                $auctionPrefix,
                $lotNumber,
                $purpose,
                $formatSellingColumns
            ),
            'auction_no' => $auctionPrefix,
            'lot_number' => $lotNumber,
            'created_at' => $product?->created_at ?? $order?->created_at,
            'purpose' => $purpose,
            'images' => $this->getImages($product, $orderItem),
            'title' => $product?->title
                ?? data_get($orderItem, 'product_title')
                ?? data_get($orderItem, 'title'),
            'listing_status' => data_get($latestHistory, 'status.status'),
            'physical_location' => $this->getAccountItemLocation(
                $latestHistory,
                $channel,
                $order
            ),
            'past_history' => $this->getPastHistory(
                $stockNumber,
                $store,
                $purpose,
                $formatSellingColumns
            ),
            'documents' => $paymentReceipt,
            'price' => $price,
            'reserve_price' => $price,
            'contract_reference' => $includeSettlement
                ? ($settlementReference ?: $agreementReference)
                : $agreementContractReference,
            'channel' => $channel,
            'sold_price' => $includeSettlement
                ? ($this->number(data_get($settlementItem, 'price')) ?? $hammerPrice)
                : null,
            'expected_settlement' => $includeSettlement
                ? $this->getExpectedSettlement($settlementItem)
                : null,
            'hammer_price' => $channel === 'buy' ? $hammerPrice : null,
            'commission' => $channel === 'buy' ? $commission : null,
            'other_fees' => $channel === 'buy' ? $otherFees : null,
            'total' => $channel === 'buy' ? $total : null,
            'payment_status' => $paymentStatus,
            'paid_at' => $this->getPaidAt(
                $paymentStatus,
                $paymentTimestampSource
            ),
            'remark' => $includeSettlement
                ? (data_get($settlementItem, 'remarks')
                    ?? $settlementDocument?->remarks
                    ?? data_get($checkout, 'approval.reason'))
                : null,
        ];
    }

    private function getAccountItemLocation(
        ?LocationHistory $history,
        string $channel,
        ?Order $order
    ): string {
        if ($channel === 'buy') {
            if (is_null($order)) {
                throw new \LogicException(
                    'Purchased account item must have an order.'
                );
            }

            return $this->getBuyerItemStatus($order, $history);
        }

        $status = $this->normalizeHistoryValue(
            data_get($history, 'status.status')
        );
        $location = $this->normalizeHistoryValue(
            data_get($history, 'location.location')
        );

        if ($status === self::STATUS_RETURNED_TO_CONSIGNOR) {
            return 'Returned to Consignor';
        }

        if (in_array($status, self::SELLER_DELIVERED_STATUSES, true)) {
            return 'Delivered to Buyer';
        }

        return $location === self::LOCATION_IN_OFFICE
            ? 'With PARAQON'
            : '-';
    }

    private function getBuyerItemStatus(
        Order $order,
        ?LocationHistory $history
    ): string {
        $currentStatus = $this->normalizeHistoryValue($order->current_status);
        $statusCreatedAt = $this->getOrderStatusCreatedAt(
            $order,
            $currentStatus
        );
        $label = match ($currentStatus) {
            'SUBMITTED', 'PROCESSING', 'PENDING' => 'Pending',
            'READY_TO_PICKUP' => 'Ready for Pick-up',
            'DELIVERING' => $this->getShippedStatusLabel($history),
            'COMPLETED' => 'Completed',
            'CANCELLED' => 'Cancelled',
        };

        return $this->withStatusCreatedAt($label, $statusCreatedAt);
    }

    private function getShippedStatusLabel(
        ?LocationHistory $history
    ): string {
        $latestHistoryStatus = $this->normalizeHistoryValue(
            data_get($history, 'status.status')
        );
        $remarks = $latestHistoryStatus === self::STATUS_PACKAGE_HAS_SHIPPED
            ? $this->getHistoryRemarks(data_get($history, 'status.remarks'))
            : '';

        return $remarks === '' ? 'Shipped' : "Shipped {$remarks}";
    }

    private function getOrderStatusCreatedAt(
        Order $order,
        string $currentStatus
    ) {
        $status = collect($order->statuses ?? [])
            ->filter(
                fn($status): bool => $this->normalizeHistoryValue(
                    data_get($status, 'slug')
                ) === $currentStatus
            )
            ->sortByDesc(fn($status) => data_get($status, 'created_at'))
            ->first();

        return data_get($status, 'created_at') ?? $order->updated_at;
    }

    private function withStatusCreatedAt(
        string $status,
        $createdAt
    ): string {
        if (is_null($createdAt) || $createdAt === '') return $status;

        try {
            if ($createdAt instanceof \DateTimeInterface) {
                $createdAt = Carbon::instance($createdAt);
            } elseif (is_object($createdAt)
                && method_exists($createdAt, 'toDateTime')) {
                $createdAt = Carbon::instance($createdAt->toDateTime());
            } elseif (is_scalar($createdAt)) {
                $createdAt = Carbon::parse((string) $createdAt);
            } else {
                return $status;
            }

            $timestamp = $createdAt
                ->setTimezone(config('app.timezone', 'Asia/Hong_Kong'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $status;
        }

        return "{$status}/{$timestamp}";
    }

    private function getHistoryRemarks($remarks): string
    {
        if (is_scalar($remarks)) return trim((string) $remarks);

        foreach (['en', 'zh', 'cn'] as $locale) {
            $localized = data_get($remarks, $locale);
            if (is_scalar($localized) && trim((string) $localized) !== '') {
                return trim((string) $localized);
            }
        }

        return '';
    }

    private function getPastHistory(
        ?string $stockNumber,
        ?Store $store,
        string $purpose,
        bool $formatSellingColumns
    ): string {
        if (!$formatSellingColumns || $purpose !== 'AUCTION') return '-';

        $auctionTitle = trim((string) data_get($store, 'title.en', ''));
        if (empty($stockNumber) || $auctionTitle === '') return '-';

        return "stock no.{$stockNumber} in {$auctionTitle}";
    }

    private function getDisplayStockNumber(
        ?string $stockNumber,
        ?string $auctionPrefix,
        $lotNumber,
        string $purpose,
        bool $formatSellingColumns
    ): ?string {
        if (!$formatSellingColumns
            || $purpose !== 'AUCTION'
            || empty($stockNumber)
            || empty($auctionPrefix)
            || is_null($lotNumber)
            || $lotNumber === '') {
            return $stockNumber;
        }

        return "{$stockNumber} / {$auctionPrefix} lot {$lotNumber}";
    }

    private function getAuctionPrefix(?Store $store): ?string
    {
        $prefix = trim((string) (
            data_get($store, 'prefix')
                ?? data_get($store, 'invoice_prefix')
                ?? ''
        ));

        return $prefix === '' ? null : $prefix;
    }

    private function getLotNumber(?AuctionLot $lot, $orderItem)
    {
        return data_get($orderItem, 'lot.number')
            ?? data_get($orderItem, 'lot_number')
            ?? data_get($lot, 'number')
            ?? data_get($lot, 'lot_number');
    }

    private function normalizeHistoryValue($value): string
    {
        $normalized = preg_replace(
            '/[^A-Z0-9]+/',
            '_',
            strtoupper(trim((string) $value))
        );

        return trim((string) $normalized, '_');
    }

    private function getPurpose(
        ?Product $product,
        ?Order $order,
        ?AuctionLot $lot,
        ?Store $store,
        array $stores,
        bool $useOrderPurpose
    ): string {
        if ($useOrderPurpose && !is_null($order)) {
            if (!empty($store?->auction_type)) return 'AUCTION';

            $isVaultOrder = !is_null($stores['main_id'])
                && (string) $order->store_id === $stores['main_id'];

            return $isVaultOrder ? 'THE_VAULT' : 'PRIVATE_SALE';
        }

        $isAuction = !empty($store?->auction_type)
            || (is_null($order) && !is_null($lot));

        if ($isAuction) return 'AUCTION';

        $categoryIDs = collect($product?->category_ids ?? [])
            ->map(fn($id) => (string) $id);
        $isVault = (!is_null($stores['main_id'])
                && (string) ($order?->store_id ?? '') === $stores['main_id'])
            || $categoryIDs->intersect($stores['vault_category_ids'])->isNotEmpty();

        return $isVault ? 'THE_VAULT' : 'PRIVATE_SALE';
    }

    private function groupOrdersByProduct(Collection $orders): Collection
    {
        $grouped = collect();

        foreach ($orders as $order) {
            foreach ($order->cart_items as $item) {
                $productID = (string) data_get($item, 'product_id', '');
                if ($productID === '') continue;

                $entries = $grouped->get($productID, collect());
                $entries->push(['order' => $order, 'item' => $item]);
                $grouped->put($productID, $entries);
            }
        }

        return $grouped->map(
            fn(Collection $items) => $items
                ->sortByDesc(fn(array $entry) => $entry['order']->created_at)
                ->values()
        );
    }

    private function groupDocumentsByProduct(Collection $documents): Collection
    {
        $grouped = collect();

        foreach ($documents as $document) {
            foreach (collect($document->items ?? []) as $item) {
                $productID = (string) data_get($item, 'product_id', '');
                if ($productID === '') continue;

                $entries = $grouped->get($productID, collect());
                $entries->push(['document' => $document, 'item' => $item]);
                $grouped->put($productID, $entries);
            }
        }

        return $grouped->map(
            fn(Collection $items) => $items
                ->sortByDesc(fn(array $entry) => $entry['document']->created_at)
                ->values()
        );
    }

    private function getMatchingLot(
        Collection $lots,
        ?Order $order
    ): ?AuctionLot {
        if (is_null($order)) return $lots->first();

        return $lots->first(
            fn(AuctionLot $lot) => (string) $lot->store_id === (string) $order->store_id
        ) ?? $lots->first();
    }

    private function getAgreement(
        Collection $documents,
        string $purpose
    ): ?array {
        $preferredType = $purpose === 'AUCTION'
            ? 'CONSIGNMENT_AGREEMENT_FOR_AUCTION'
            : 'PRIVATE_SALE_AGREEMENT';

        return $this->getDocumentEntry($documents, $preferredType);
    }

    private function getAgreementContractReference(?array $agreement): ?array
    {
        /** @var ?Document $document */
        $document = data_get($agreement, 'document');
        if (is_null($document)) return null;

        $number = trim((string) ($document->statement_no ?? ''));
        $file = collect($document->documents ?? [])->first(
            fn($file) =>
                data_get($file, 'type') === $document->type
                && !empty(data_get($file, 'url'))
        );
        $url = trim((string) data_get($file, 'url', ''));

        if ($number === '' && $url === '') return null;

        return [
            'number' => $number === '' ? null : $number,
            'url' => $url === '' ? null : $url,
        ];
    }

    private function getDocumentEntry(
        Collection $documents,
        string $type
    ): ?array {
        return $documents->first(
            fn(array $entry) => $entry['document']->type === $type
        );
    }

    private function getSettlementPaymentReceipt(
        ?Document $settlementDocument
    ): ?array {
        if (is_null($settlementDocument)) return null;

        $receipt = collect($settlementDocument->documents ?? [])->first(
            fn($document) =>
                data_get($document, 'type') === 'PAYMENT_RECEIPT'
                && !empty(data_get($document, 'url'))
        );
        $url = data_get($receipt, 'url');
        if (empty($url)) return null;

        return [
            'en' => $url,
            'zh' => $url,
            'cn' => $url,
        ];
    }

    private function getHammerPrice($orderItem, string $purpose): ?float
    {
        if ($purpose === 'AUCTION') {
            return $this->number(
                data_get($orderItem, 'winning_bid')
                    ?? data_get($orderItem, 'current_bid')
            );
        }

        return $this->number(
            data_get($orderItem, 'subtotal_price')
                ?? data_get($orderItem, 'original_subtotal_price')
                ?? data_get($orderItem, 'discounted_price_per_unit')
                ?? data_get($orderItem, 'original_price_per_unit')
        );
    }

    private function getExpectedSettlement($settlementItem): ?float
    {
        if (is_null($settlementItem)) return null;

        $price = $this->number(data_get($settlementItem, 'price'));
        if (is_null($price)) return null;

        $commission = $this->number(
            data_get($settlementItem, 'commission')
        ) ?? 0.0;
        $otherCharges = $this->number(
            data_get($settlementItem, 'other_charges')
        ) ?? 0.0;

        return $price - $commission - $otherCharges;
    }

    private function getPurchasedTotal(
        $orderItem,
        ?float $hammerPrice,
        float $commission
    ): ?float {
        $storedTotal = $this->number(data_get($orderItem, 'sold_price'));
        if (!is_null($storedTotal)) return $storedTotal;

        return is_null($hammerPrice) ? null : $hammerPrice + $commission;
    }

    private function getSettlementPaymentStatus(
        ?Document $settlementDocument
    ): string
    {
        if (is_null($settlementDocument)) return 'PENDING';

        $hasSettlementFile = collect($settlementDocument->documents ?? [])
            ->contains(
                fn($document) =>
                    data_get($document, 'type') === 'CONSIGNOR_SETTLEMENT'
                    && !empty(data_get($document, 'url'))
            );

        return $hasSettlementFile ? 'PAID' : 'PENDING';
    }

    private function getOrderPaymentStatus(
        ?Order $order,
        ?Checkout $checkout
    ): ?string {
        if (is_null($order)) return null;
        if ($order->is_paid) return 'PAID';

        $currentStatus = strtoupper(str_replace('-', '_', (string) $order->current_status));
        $approvalStatus = strtoupper((string) data_get($checkout, 'approval.status', ''));

        if ($currentStatus === 'CANCELLED'
            || in_array($approvalStatus, ['CANCELLED', 'REJECTED'], true)) {
            return 'CANCELLED';
        }

        if ($currentStatus !== '') return $currentStatus;
        return $approvalStatus !== '' ? $approvalStatus : 'PENDING';
    }

    private function getPaidAt(?string $paymentStatus, $source)
    {
        if ($paymentStatus !== 'PAID' || is_null($source)) return null;

        return data_get($source, 'approval.updated_at')
            ?? data_get($source, 'approval.created_at')
            ?? data_get($source, 'updated_at');
    }

    private function getStockNumber(?Product $product, $orderItem): ?string
    {
        if (is_null($product)) {
            $stockNumber = data_get($orderItem, 'stock_no');
            return empty($stockNumber) ? null : (string) $stockNumber;
        }

        $stockNumber = trim((string) ($product->prefix ?? '') . (string) ($product->stock_no ?? ''));
        return $stockNumber === '' ? null : $stockNumber;
    }

    private function getImages(?Product $product, $orderItem): array
    {
        $images = $product?->images ?? [];
        if (is_array($images) && count($images) > 0) return $images;

        $image = data_get($orderItem, 'image');
        return empty($image) ? [] : [$image];
    }

    private function number($value): ?float
    {
        return is_null($value) || $value === '' ? null : (float) $value;
    }

    private function latest(Collection $items)
    {
        return $items->sortByDesc('created_at')->first();
    }

    private function latestFirst(Collection $items): Collection
    {
        return $items->sortByDesc('created_at')->values();
    }
}
