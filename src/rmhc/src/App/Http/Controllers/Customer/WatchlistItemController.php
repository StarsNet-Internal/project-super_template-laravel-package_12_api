<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

use App\Enums\Status;
use App\Enums\ProductVariantDiscountType;

use App\Models\Store;
use App\Models\Product;

use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;
use Starsnet\Project\Rmhc\App\Models\WinningCustomerHistory;

class WatchlistItemController extends Controller
{
    public function getWatchedAuctionLots(): Collection
    {
        $watchingAuctionLotIDs = WatchlistItem::where('customer_id', $this->customer()->id)
            ->where('item_type', 'auction-lot')
            ->pluck('item_id')
            ->all();

        $productIDs = AuctionLot::whereIn('_id', $watchingAuctionLotIDs)
            ->get()
            ->pluck('product_id')
            ->all();

        // Get winning_customer_ids
        $latestHistories = WinningCustomerHistory::query()
            ->whereIn('auction_lot_id', $watchingAuctionLotIDs)
            ->orderByDesc('created_at')
            ->get()
            ->unique('auction_lot_id')
            ->keyBy('auction_lot_id');

        // Get Products
        $products = $this->getProductsInfoByAggregation($productIDs);
        $products = $products->filter(fn($item) =>  $item->auction_lot_id != '0')->values();

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
            $product->is_watching = true;

            // winning customer ids
            $winnerHistory = $latestHistories[$auctionLot->id] ?? null;
            $product->winning_customers = $winnerHistory?->winning_customers;

            unset(
                $product->bids,
                $product->valid_bid_values,
                $product->reserve_price
            );
        }

        return $products;
    }

    private function getProductsInfoByAggregation(array $productIDs): Collection
    {
        $productIDs = array_values($productIDs);

        // Get Products 
        if (count($productIDs) == 0) return new Collection();

        // Get StoreIDs
        $validStoreIDs = Store::whereIn('status', [Status::ACTIVE->value, Status::ARCHIVED->value])
            ->get()
            ->pluck('id')
            ->all();

        $products = Product::raw(function ($collection) use ($productIDs, $validStoreIDs) {
            $aggregate = [];

            // Convert ObjectIDs to String
            $aggregate[]['$addFields'] = [
                '_id' => [
                    '$toString' => '$_id'
                ]
            ];

            // Find matching IDs
            $aggregate[]['$match'] = [
                '_id' => [
                    '$in' => $productIDs
                ]
            ];

            // Get AuctionLot
            $aggregate[]['$lookup'] = [
                'from' => 'auction_lots',
                'let' => ['product_id' => '$_id'],
                'pipeline' => [
                    [
                        '$match' => [
                            '$expr' => [
                                '$and' => [
                                    [
                                        '$in' => [
                                            '$store_id',
                                            $validStoreIDs
                                        ]
                                    ],
                                    ['$eq' => ['$product_id', '$$product_id']],
                                    [
                                        '$in' => [
                                            '$status',
                                            [
                                                Status::DRAFT->value,
                                                Status::ACTIVE->value,
                                                Status::ARCHIVED->value
                                            ]
                                        ]
                                    ]
                                ],
                            ],
                        ],
                    ],
                    // ! Laravel 12 Required
                    [
                        '$addFields' => [
                            'id' => ['$toString' => '$_id']
                        ]
                    ],
                    // ! Laravel 12 Required
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
                                    '$eq' => ['$local_discount_type', ProductVariantDiscountType::PRICE->value]
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
                                    '$eq' => ['$local_discount_type', ProductVariantDiscountType::PERCENTAGE->value]
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

            // Get watchlist_items(s)
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
