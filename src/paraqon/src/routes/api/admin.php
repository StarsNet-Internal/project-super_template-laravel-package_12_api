<?php

// Default Imports
use Illuminate\Support\Facades\Route;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\AccountController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\AuctionController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\AuctionLotController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\AuctionRegistrationRequestController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\ConsignmentRequestController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\CustomerController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\CustomerGroupController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\DepositController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\DocumentController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\ProductController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\BidController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\OrderController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\ServiceController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\ShoppingCartController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\LiveBiddingEventController;
use StarsNet\Project\Paraqon\App\Http\Controllers\Admin\LocationHistoryController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\NotificationController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\WatchlistItemController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\VerificationCodeController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\TestingController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(
    ['prefix' => 'tests'],
    function () {
        Route::get('/health-check', [TestingController::class, 'healthCheck']);
        Route::get('/order', [TestingController::class, 'createOrder']);
    }
);

Route::group(
    ['prefix' => 'accounts'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::put('/{id}/verification', [AccountController::class, 'updateAccountVerification']);
                Route::put('/{id}/details', [AccountController::class, 'updateAccountDetails']);
            }
        );
    }
);

// VERIFICATION CODE
Route::group(
    ['prefix' => 'verification-codes'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [VerificationCodeController::class, 'getAllVerificationCodes'])->middleware(['pagination']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'auctions'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::put('/statuses', [AuctionController::class, 'updateAuctionStatuses']);
                Route::get('/{store_id}/archive', [AuctionController::class, 'archiveAllAuctionLots']);
                Route::get('/{store_id}/orders/create', [AuctionController::class, 'generateAuctionOrders']);

                Route::get('/{store_id}/auction-registration-requests/all', [AuctionController::class, 'getAllAuctionRegistrationRequests'])->middleware(['pagination']);

                Route::get('/{store_id}/registered-users', [AuctionController::class, 'getAllRegisteredUsers'])->middleware(['pagination']);
                Route::put('/{store_id}/registered-users/{customer_id}/remove', [AuctionController::class, 'removeRegisteredUser']);
                Route::put('/{store_id}/registered-users/{customer_id}/add', [AuctionController::class, 'addRegisteredUser']);

                Route::get('/{store_id}/categories/all', [AuctionController::class, 'getAllCategories'])->middleware(['pagination']);
                Route::get('/{store_id}/registration-records', [AuctionController::class, 'getAllAuctionRegistrationRecords'])->middleware(['pagination']);
                Route::put('/products/{product_id}/categories/assign', [AuctionController::class, 'syncCategoriesToProduct']);
                Route::get('/all', [AuctionController::class, 'getAllAuctions'])->middleware(['pagination']);

                Route::get('/{store_id}/auction-lots/unpaid', [AuctionController::class, 'getAllUnpaidAuctionLots'])->middleware(['pagination']);
                Route::put('/{store_id}/auction-lots/return', [AuctionController::class, 'returnAuctionLotToOriginalCustomer']);

                Route::get('/{store_id}/close', [AuctionController::class, 'closeAllNonDisabledLots']);

                Route::post('/aggregate', [AuctionController::class, 'aggregate']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'auction-lots'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [AuctionLotController::class, 'createAuctionLot']);
                Route::get('/all', [AuctionLotController::class, 'getAllAuctionLots'])->middleware(['pagination']);
                Route::get('/all/trimmed', [AuctionLotController::class, 'getAllAuctionLotsTrimmed'])->middleware(['pagination']);
                Route::get('/{id}/details', [AuctionLotController::class, 'getAuctionLotDetails']);
                Route::get('/{id}/bids/all', [AuctionLotController::class, 'getAllAuctionLotBids'])->middleware(['pagination']);
                Route::put('/mass-update', [AuctionLotController::class, 'massUpdateAuctionLots']);
                Route::put('/{id}/details', [AuctionLotController::class, 'updateAuctionLotDetails']);
                Route::put('/delete', [AuctionLotController::class, 'deleteAuctionLots']);

                // Live Auction
                Route::post('/{auction_lot_id}/live-bids', [AuctionLotController::class, 'createLiveBid']);
                Route::get('/{auction_lot_id}/reset', [AuctionLotController::class, 'resetAuctionLot']);
                Route::put('/{auction_lot_id}/bid-histories/last', [AuctionLotController::class, 'updateBidHistoryLastItem']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'auction-registrations'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/register', [AuctionRegistrationRequestController::class, 'registerAuction']);
                Route::get('/all', [AuctionRegistrationRequestController::class, 'getAllRegisteredAuctions'])->middleware(['pagination']);
                Route::put('/{id}/archive', [AuctionRegistrationRequestController::class, 'archiveAuctionRegistrationRequest']);
                Route::put('/{id}/details', [AuctionRegistrationRequestController::class, 'updateAuctionRegistrationRequest']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'consignments'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [ConsignmentRequestController::class, 'getAllConsignmentRequests'])->middleware(['pagination']);
                Route::post('/', [ConsignmentRequestController::class, 'createConsignmentRequest']);
                Route::get('/{id}/details', [ConsignmentRequestController::class, 'getConsignmentRequestDetails']);
                Route::put('/{id}/details', [ConsignmentRequestController::class, 'updateConsignmentRequestDetails']);
                Route::put('/{id}/approve', [ConsignmentRequestController::class, 'approveConsignmentRequest']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'customers'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/login', [CustomerController::class, 'loginAsCustomer']);
                Route::get('/all', [CustomerController::class, 'getAllCustomers'])->middleware(['pagination']);
                Route::get('/{id}/details', [CustomerController::class, 'getCustomerDetails']);
                Route::get('/{customer_id}/products/all', [CustomerController::class, 'getAllOwnedProducts'])->middleware(['pagination']);
                Route::get('/{customer_id}/auction-lots/all', [CustomerController::class, 'getAllOwnedAuctionLots'])->middleware(['pagination']);
                Route::get('/{customer_id}/bids/all', [CustomerController::class, 'getAllBids'])->middleware(['pagination']);
                Route::put('/{customer_id}/bids/{bid_id}/hide', [CustomerController::class, 'hideBid']);
            }
        );
    }
);

// CUSTOMER_GROUP
Route::group(
    ['prefix' => 'customer-groups'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/{id}/customers/assign', [CustomerGroupController::class, 'getCustomerGroupAssignedCustomers'])->middleware(['pagination']);
                Route::get('/{id}/customers/unassign', [CustomerGroupController::class, 'getCustomerGroupUnassignedCustomers'])->middleware(['pagination']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'documents'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [DocumentController::class, 'createDocument']);
                Route::get('/all', [DocumentController::class, 'getAllDocuments'])->middleware(['pagination']);
                Route::get('/{id}/details', [DocumentController::class, 'getDocumentDetails']);
                Route::put('/{id}/details', [DocumentController::class, 'updateDocumentDetails']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'services'],
    function () {
        Route::put('/auctions/statuses', [ServiceController::class, 'updateAuctionStatuses']);
        Route::put('/auction-lots/statuses', [ServiceController::class, 'updateAuctionLotStatuses']);
        Route::put('/auction-lots/{auction_lot_id}/extend', [ServiceController::class, 'extendAuctionLotEndDateTime']);

        Route::post('/payment/callback', [ServiceController::class, 'paymentCallback']);
        Route::post('/auctions/{store_id}/orders/create', [ServiceController::class, 'generateAuctionOrdersAndRefundDeposits']);
        Route::post('/auctions/{store_id}/orders/create-live', [ServiceController::class, 'generateLiveAuctionOrdersAndRefundDeposits']);

        Route::post('/deposits/return', [ServiceController::class, 'returnDeposit']);
        Route::post('/orders/paid', [ServiceController::class, 'confirmOrderPaid']);

        Route::post('/algolia/stores/{store_id}/products', [ServiceController::class, 'synchronizeAllProductsWithAlgolia']);

        Route::get('/auctions/{store_id}/state', [ServiceController::class, 'getAuctionCurrentState']);
        Route::get('/orders/capture', [ServiceController::class, 'captureOrderPayment']);

        Route::post('/users/cleanup', [ServiceController::class, 'deleteAllTemporaryUsers']);
    }
);

Route::group(
    ['prefix' => 'deposits'],
    function () {

        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [DepositController::class, 'getAllDeposits'])->middleware(['pagination']);
                Route::get('/{id}/details', [DepositController::class, 'getDepositDetails']);
                Route::put('/{id}/details', [DepositController::class, 'updateDepositDetails']);
                Route::put('/{id}/approve', [DepositController::class, 'approveDeposit']);
                Route::put('/{id}/cancel', [DepositController::class, 'cancelDeposit']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'orders'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [OrderController::class, 'getAllAuctionOrders'])->middleware(['pagination']);
                Route::put('/{order_id}/upload', [OrderController::class, 'uploadPaymentProofAsCustomer']);
                Route::put('/{order_id}/details', [OrderController::class, 'updateOrderDetails']);
                Route::put('/{order_id}/cancel', [OrderController::class, 'cancelOrderPayment']);
                Route::get('/{id}/invoice/{language}', [OrderController::class, 'getInvoiceData']);
                Route::put('/{id}/offline-payment', [OrderController::class, 'approveOrderOfflinePayment']);
            }
        );
    }
);

Route::group(
    ['prefix' => '/stores/{store_id}/shopping-cart'],
    function () {
        Route::group(['middleware' => 'auth:api'], function () {
            Route::post('/all', [ShoppingCartController::class, 'getAll']);
            Route::post('/checkout', [ShoppingCartController::class, 'checkOut']);
            Route::post('/private-sale/all', [ShoppingCartController::class, 'privateSaleGetAll']);
            Route::post('/private-sale/checkout', [ShoppingCartController::class, 'privateSaleCheckOut']);
        });
    }
);

Route::group(
    ['prefix' => 'shopping-cart'],
    function () {
        Route::group(['middleware' => 'auth:api'],  function () {
            Route::get('/all', [ShoppingCartController::class, 'getShoppingCartItems'])->middleware(['pagination']);
        });
    }
);

Route::group(
    ['prefix' => 'live-bidding'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/{store_id}/events', [LiveBiddingEventController::class, 'createEvent']);
            }
        );
    }
);


Route::group(
    ['prefix' => 'products'],
    function () {
        Route::get('/all', [ProductController::class, 'getAllProducts'])->middleware(['pagination']);
        Route::post('/', [ProductController::class, 'createProduct']);
    }
);


Route::group(
    ['prefix' => 'notifications'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [NotificationController::class, 'createNotification']);
                Route::get('/all', [NotificationController::class, 'getAllNotifications'])->middleware(['pagination']);
                Route::put('/read', [NotificationController::class, 'markNotificationsAsRead']);
                Route::put('/{id}/delete', [NotificationController::class, 'deleteNotification']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'watchlist'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/customers/all', [WatchlistItemController::class, 'getAllWatchlistedCustomers'])->middleware(['pagination']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'bids'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/auction-lots/{auction_lot_id}/max', [BidController::class, 'createOnlineBidByCustomer']);
                Route::get('/customers/{customer_id}/all', [BidController::class, 'getCustomerAllBids'])->middleware(['pagination']);
                Route::put('/{bid_id}/cancel', [BidController::class, 'cancelBidByCustomer']);
            }
        );
    }
);


Route::group(
    ['prefix' => 'locations-histories'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [LocationHistoryController::class, 'getAllLocationHistories'])->middleware(['pagination']);
                Route::post('/products/{product_id}', [LocationHistoryController::class, 'createHistory']);
                Route::put('/mass-update', [LocationHistoryController::class, 'massUpdateLocationHistories']);
            }
        );
    }
);
