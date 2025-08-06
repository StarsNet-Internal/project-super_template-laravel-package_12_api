<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

// Enums
use App\Enums\Status;
use App\Enums\StoreType;
use App\Enums\ProductVariantDiscountType;

// Models
use App\Models\Alias;
use App\Models\Store;
use App\Models\Category;
use App\Models\Product;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

class SiteMapController extends Controller
{
    public function getAllAuctions(Request $request): Collection
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('status', [
            Status::ACTIVE->value,
            Status::ARCHIVED->value
        ]);

        // Get Auction Store(s)
        $auctions = Store::whereType(StoreType::OFFLINE->value)
            ->statuses($statuses)
            ->get();

        foreach ($auctions as $auction) {
            $auction->is_watching = false;
            $auction->auction_registration_request = null;
            $auction->is_registered = false;
            $auction->deposits = [];
        }

        return $auctions;
    }

    public function filterAuctionProductsByCategories(Request $request)
    {
        // Get Store via id
        $store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
        if (is_null($store)) return [];

        // Extract attributes from $request
        $categoryIDs = array_unique((array) $request->input('category_ids'));

        // Get all ProductCategory(s)
        if (count($categoryIDs) === 0) {
            $categoryIDs = $store
                ->productCategories()
                ->statusActive()
                ->get()
                ->pluck('id')
                ->all();
        }

        // Get Product(s) from selected ProductCategory(s)
        $productIDs = AuctionLot::where('store_id', $store->id)
            ->statuses([Status::ACTIVE->value, Status::ARCHIVED->value])
            ->get()
            ->pluck('product_id')
            ->all();

        if (count($categoryIDs) > 0) {
            $allProductCategoryIDs = Category::where('slug', 'all-products')->pluck('id')->all();
            if (!array_intersect($categoryIDs, $allProductCategoryIDs)) {
                $productIDs = Product::objectIDs($productIDs)
                    ->whereHas('categories', function ($query) use ($categoryIDs) {
                        $query->whereIn('_id', $categoryIDs);
                    })
                    ->statuses([Status::ACTIVE->value, Status::ARCHIVED->value])
                    ->get()
                    ->pluck('id')
                    ->all();
            }
        }

        // Filter Product(s)
        $products = $this->getProductsInfoByAggregation($productIDs, $store->id);
        $products = $products->filter(function ($item) {
            return $item->auction_lot_id != '0';
        })->values();

        foreach ($products as $product) {
            $auctionLotID = $product->auction_lot_id;
            $auctionLot = AuctionLot::find($auctionLotID);

            $product->current_bid = $auctionLot->getCurrentBidPrice();
            $product->is_reserve_price_met = $product->current_bid >= $product->reserve_price;

            $product->title = $auctionLot->title;
            $product->short_description = $auctionLot->short_description;
            $product->long_description = $auctionLot->long_description;
            $product->bid_incremental_settings = $auctionLot->bid_incremental_settings;
            $product->start_datetime = $auctionLot->start_datetime;
            $product->end_datetime = $auctionLot->end_datetime;
            $product->lot_number = $auctionLot->lot_number;
            $product->status = $auctionLot->status;
            $product->is_disabled = $auctionLot->is_disabled;
            $product->is_closed = $auctionLot->is_closed;

            $product->sold_price = $auctionLot->sold_price;
            $product->commission = $auctionLot->commission;

            $product->max_estimated_price = data_get($auctionLot, 'bid_incremental_settings.estimate_price.max') ?? 0;
            $product->min_estimated_price = data_get($auctionLot, 'bid_incremental_settings.estimate_price.min') ?? 0;

            // is_watching
            $auctionLot->is_watching = false;

            unset(
                $product->bids,
                $product->valid_bid_values,
                $product->reserve_price
            );
        }

        return $products;
    }

    public function getAuctionLotDetails(Request $request): AuctionLot
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::with([
            'product',
            'productVariant',
            'store',
            'bids'
        ])->find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if (!in_array($auctionLot->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) {
            abort(404, 'Auction is not available for public');
        }

        // Append keys
        $auctionLot->is_liked = false;
        $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        $auctionLot->is_reserve_price_met = $auctionLot->current_bid >= $auctionLot->reserve_price;
        $auctionLot->is_watching = false;

        return $auctionLot;
    }

    private function getProductsInfoByAggregation(array $productIDs, ?string $storeID = null): Collection
    {
        $productIDs = array_values($productIDs);

        // Get Products 
        if (count($productIDs) == 0) return new Collection();

        $products = Product::raw(function ($collection) use ($productIDs, $storeID) {
            $aggregate = [];

            // Convert ObjectIDs to String
            $aggregate[]['$addFields'] = [
                '_id' => [
                    '$toString' => '$_id'
                ]
            ];

            // Find matching IDs
            if (count($productIDs) > 0) {
                $aggregate[]['$match'] = [
                    '_id' => [
                        '$in' => $productIDs
                    ]
                ];
            }

            // Get AuctionLot
            $aggregate[]['$lookup'] = [
                'from' => 'auction_lots',
                'let' => ['product_id' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    !is_null($storeID) ? ['$eq' => ['$store_id', $storeID]] : [],
                                    ['$eq' => ['$product_id', '$$product_id']],
                                    [
                                        '$in' => [
                                            '$status',
                                            [Status::ACTIVE, Status::ARCHIVED]
                                        ]
                                    ]
                                ],
                            ],
                        ],
                    ],
                ],
                'as' => 'auction_lots',
            ];

            // Get Bid
            $aggregate[]['$lookup'] = [
                'from' => 'bids',
                'let' => ['product_id' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    // ['$eq' => ['$store_id', $storeID]],
                                    ['$eq' => ['$product_id', '$$product_id']],
                                    ['$eq' => ['$is_hidden', false]],
                                ],
                            ],
                        ],
                    ],
                ],
                'as' => 'bids',
            ];

            $aggregate[]['$addFields'] = [
                'starting_price' => [
                    '$last' => '$auction_lots.starting_price'
                ],
                'reserve_price' => [
                    '$last' => '$auction_lots.reserve_price'
                ],
                'valid_bid_values' => [
                    '$map' => [
                        'input' => '$bids',
                        'as' => 'bid',
                        'in' => '$$bid.bid'
                    ]
                ]
            ];

            // Get ProductVariants
            $aggregate[]['$lookup'] = [
                'from' => 'product_variants',
                'let' => ['product_id' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    ['$eq' => ['$status', 'ACTIVE']],
                                    ['$eq' => ['$product_id', '$$product_id']],
                                ],
                            ],
                        ],
                    ],
                ],
                'as' => 'variants',
            ];

            // Get first ACTIVE ProductVariant
            $aggregate[]['$addFields'] = [
                'first_product_variant_id' => [
                    '$toString' => ['$first' => '$variants._id'],
                ],
            ];

            // Get ProductVariantDiscount(s)
            $aggregate[]['$lookup'] = [
                'from' => 'product_variant_discounts',
                'let' => ['product_variant_id' => '$first_product_variant_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    ['$lt' => ['$start_datetime', '$$NOW']],
                                    ['$gte' => ['$end_datetime', '$$NOW']],
                                    ['$eq' => ['$status', 'ACTIVE']],
                                    [
                                        '$eq' => [
                                            '$product_variant_id',
                                            '$$product_variant_id',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'as' => 'local_discounts',
            ];

            // Get GlobalDiscounts
            $aggregate[]['$lookup'] = [
                'from' => 'discount_templates',
                'let' => ['product_variant_id' => '$first_product_variant_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    // [
                                    //     '$in' => [
                                    //         $storeID,
                                    //         '$store_ids',
                                    //     ],
                                    // ],
                                    ['$lt' => ['$start_datetime', '$$NOW']],
                                    ['$gte' => ['$end_datetime', '$$NOW']],
                                    ['$eq' => ['$status', 'ACTIVE']],
                                    [
                                        '$eq' => [
                                            '$$product_variant_id',
                                            '$x.product_variant_id',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'as' => 'global_discounts',
            ];

            // Get Review(s)
            $aggregate[]['$lookup'] = [
                'from' => 'reviews',
                'let' => ['product_id' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    ['$eq' => ['$status', 'ACTIVE']],
                                    ['$eq' => ['$model_type_id', '$$product_id']],
                                ],
                            ],
                        ],
                    ],
                ],
                'as' => 'reviews',
            ];

            // Append Info
            $aggregate[]['$addFields'] = [
                'price' => ['$ifNull' => [['$first' => '$variants.price'], 0]],
                'point' => ['$ifNull' => [['$first' => '$variants.point'], 0]],
                'is_free_shipping' => ['$ifNull' => [['$first' => '$variants.is_free_shipping'], false]],
                'local_discount_type' => ['$ifNull' => [['$first' => '$local_discounts.type'], null]],
                'local_discount_value' => ['$ifNull' => [['$first' => '$local_discounts.value'], 0]],
                'global_discount' => [
                    '$cond' => [
                        'if' => ['$eq' => [['$size' => '$global_discounts'], 0]],
                        'then' => null,
                        'else' => ['$first' => '$global_discounts'],
                    ],
                ],
                'rating' => ['$avg' => '$reviews.rating'],
                'review_count' => ['$size' => '$reviews'],
                // 'inventory_count' => ['$sum' => '$inventories.qty'],
                'inventory_count' => 0,
                'is_liked' => false,
                'is_watching' => false,
            ];

            // Append discounted_price
            $aggregate[]['$addFields'] = [
                'discounted_price' => [
                    '$switch' => [
                        'branches' => [
                            [
                                'case' => [
                                    '$eq' => ['$local_discount_type', ProductVariantDiscountType::PRICE]
                                ],
                                'then' => [
                                    '$subtract' => [
                                        '$price',
                                        '$local_discount_value'
                                    ]
                                ]
                            ],
                            [
                                'case' => [
                                    '$eq' => ['$local_discount_type', ProductVariantDiscountType::PERCENTAGE]
                                ],
                                'then' => [
                                    '$divide' => [
                                        [
                                            '$multiply' => [
                                                '$price',
                                                [
                                                    '$subtract' => [
                                                        100,
                                                        '$local_discount_value'
                                                    ]
                                                ]
                                            ]
                                        ],
                                        100
                                    ]
                                ]
                            ]
                        ],
                        'default' => '$price'
                    ],
                ]
            ];

            $aggregate[]['$addFields'] = [
                'discounted_price' => [
                    '$cond' => [
                        'if' => [
                            '$lte' => [
                                '$discounted_price',
                                0
                            ],
                        ],
                        'then' =>  '0',
                        'else' => [
                            '$toString' => [
                                '$round' => [
                                    '$discounted_price',
                                    2
                                ]
                            ]
                        ],
                    ],
                ]
            ];

            $aggregate[]['$addFields'] = [
                // 'current_bid' => [
                //     '$cond' => [
                //         'if' => [
                //             '$gt' => [
                //                 ['$size' => '$auction_lots'],
                //                 0
                //             ]
                //         ],
                //         'then' => ['$first' => '$auction_lots.current_bid'],
                //         'else' => 0
                //     ],
                // ],
                // 'is_reserve_price_met' => [
                //     '$cond' => [
                //         'if' => [
                //             '$gte' => [
                //                 ['$first' => '$auction_lots.current_bid'],
                //                 ['$first' => '$auction_lots.reserve_price'],
                //             ],
                //         ],
                //         'then' => true,
                //         'else' => false
                //     ],
                // ],
                'is_bid_placed' => ['$last' => '$auction_lots.is_bid_placed'],
                'auction_lot_id' => [
                    '$cond' => [
                        'if' => [
                            '$gt' => [
                                ['$size' => '$auction_lots'],
                                0
                            ]
                        ],
                        'then' => [
                            '$toString' => ['$last' => '$auction_lots._id']
                        ],
                        'else' => '0'
                    ],
                ],
                'store_id' => [
                    '$cond' => [
                        'if' => [
                            '$gt' => [
                                ['$size' => '$auction_lots'],
                                0
                            ]
                        ],
                        'then' => [
                            '$toString' => ['$last' => '$auction_lots.store_id']
                        ],
                        'else' => '0'
                    ],
                ],
            ];

            // Get WishlistItem(s)
            $aggregate[]['$lookup'] = [
                'from' => 'watchlist_items',
                'localField' => 'auction_lot_id',
                'foreignField' => 'item_id',
                'as' => 'watchlist_items',
            ];

            // Append is_liked field
            if (Auth::check()) {
                // Get authenticated User information
                $customer = $this->customer();

                $aggregate[]['$addFields'] = [
                    'watchlist_item_count' => ['$size' => '$watchlist_items'],
                    'is_watching' => [
                        '$cond' => [
                            'if' => [
                                '$in' => [
                                    $customer->_id,
                                    '$watchlist_items.customer_id',
                                ],
                            ],
                            'then' => true,
                            'else' => false,
                        ],
                    ],
                ];
            }

            // Get store(s)
            $aggregate[]['$lookup'] = [
                'from' => 'stores',
                'let' => ['store_id' => '$store_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$eq' => [['$toString' => '$_id'], '$$store_id']
                            ]
                        ]
                    ]
                ],
                'as' => 'store',
            ];

            $aggregate[]['$unwind'] = [
                'path' => '$store',
                'preserveNullAndEmptyArrays' => true
            ];

            // Hide attributes
            $hiddenKeys = [
                'discount',
                'remarks',
                'status',
                'is_system',
                'deleted_at',
                'variants',
                'local_discounts',
                'local_discount_value',
                'global_discounts',
                'reviews',
                'inventories',
                // 'watchlist_items',
                'valid_bid_values',
                'bids',
                // 'auction_lots',
                'listing_status',
                // 'owned_by_customer_id',
                'store_id'
            ];
            $aggregate[]['$project'] = array_merge(...array_map(function ($item) {
                return [$item => false];
            }, $hiddenKeys));

            return $collection->aggregate($aggregate);
        });

        return $products;
    }
}
