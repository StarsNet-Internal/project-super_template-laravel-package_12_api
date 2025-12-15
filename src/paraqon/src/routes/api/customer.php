<?php

// Default Imports
use Illuminate\Support\Facades\Route;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AccountController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuctionController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuctionLotController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuctionRegistrationRequestController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuctionRequestController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuthController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuthenticationController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\BidController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\ConsignmentRequestController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\DepositController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\DocumentController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\OrderController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\PaymentController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\ProductController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\ProductManagementController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\ServiceController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\ShoppingCartController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\TestingController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\WatchlistItemController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\NotificationController;

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
        Route::post('/cart', [TestingController::class, 'cart']);
        Route::get('/health-check', [TestingController::class, 'healthCheck']);
        Route::get('/callback', [TestingController::class, 'callbackTest']);
    }
);

Route::group(
    ['prefix' => 'auth'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/customer', [AuthController::class, 'getCustomerInfo']);
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
                Route::get('/all', [AuctionController::class, 'getAllAuctions'])->middleware(['pagination']);
                Route::get('/{auction_id}/paddles/all', [AuctionController::class, 'getAllPaddles'])->middleware(['pagination']);
                Route::get('/{auction_id}/details', [AuctionController::class, 'getAuctionDetails']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'auction-requests'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [AuctionRequestController::class, 'createAuctionRequest']);
                Route::get('/all', [AuctionRequestController::class, 'getAllAuctionRequests'])->middleware(['pagination']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'auction-lots'],
    function () {
        Route::get('/{auction_lot_id}/bids', [AuctionLotController::class, 'getBiddingHistory'])->middleware(['pagination']);
        Route::get('/{auction_lot_id}/bids/all', [AuctionLotController::class, 'getAllAuctionLotBids'])->middleware(['pagination']);

        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/{auction_lot_id}/details', [AuctionLotController::class, 'getAuctionLotDetails']);

                Route::get('/owned/all', [AuctionLotController::class, 'getAllOwnedAuctionLots'])->middleware(['pagination']);
                Route::get('/participated/all', [AuctionLotController::class, 'getAllParticipatedAuctionLots'])->middleware(['pagination']);
                Route::post('/{auction_lot_id}/bids', [AuctionLotController::class, 'createMaximumBid']);
                Route::put('/{auction_lot_id}/bid-requests', [AuctionLotController::class, 'requestForBidPermissions']);

                Route::post('/{auction_lot_id}/live-bids', [AuctionLotController::class, 'createLiveBid']);
            }
        );
    }
);


Route::group(
    ['prefix' => 'auth'],
    function () {
        Route::post('/login', [AuthenticationController::class, 'login']);
        Route::post('/2fa-login', [AuthenticationController::class, 'twoFactorAuthenticationlogin']);

        Route::post('/change-phone', [AuthenticationController::class, 'changePhone']);

        Route::post('/forget-password', [AuthenticationController::class, 'forgetPassword']);
        Route::post('/reset-password', [AuthenticationController::class, 'resetPassword']);

        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/update-password', [AuthenticationController::class, 'updatePassword']);
                Route::post('/migrate', [AuthenticationController::class, 'migrateToRegistered']);

                Route::get('/change-email-request', [AuthenticationController::class, 'changeEmailRequest']);
                Route::get('/change-phone-request', [AuthenticationController::class, 'changePhoneRequest']);

                Route::get('/user', [AuthenticationController::class, 'getAuthUserInfo']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'accounts'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::put('/verification', [AccountController::class, 'updateAccountVerification']);
                Route::get('/customer-groups', [AccountController::class, 'getAllCustomerGroups'])->middleware(['pagination']);
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
                Route::get('/all', [BidController::class, 'getAllBids']);
                Route::put('/{id}/cancel', [BidController::class, 'cancelBid']);
                Route::put('/{id}/cancel-live', [BidController::class, 'cancelLiveBid']);
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
                Route::post('/', [ConsignmentRequestController::class, 'createConsignmentRequest']);
                Route::get('/all', [ConsignmentRequestController::class, 'getAllConsignmentRequests'])->middleware(['pagination']);
                Route::get('/{consignment_request_id}/details', [ConsignmentRequestController::class, 'getConsignmentRequestDetails']);
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
                Route::get('/stores/{store_id}/all', [OrderController::class, 'getOrdersByStoreID'])->middleware(['pagination']);
                Route::get('/all/offline', [OrderController::class, 'getAllOfflineOrders'])->middleware(['pagination']);
                Route::get('/all/store-type', [OrderController::class, 'getAllOrdersByStoreType'])->middleware(['pagination']);
                Route::put('/{order_id}/upload', [OrderController::class, 'uploadPaymentProofAsCustomer']);
                Route::post('/{order_id}/payment', [OrderController::class, 'payPendingOrderByOnlineMethod']);
                Route::put('/{order_id}/details', [OrderController::class, 'updateOrderDetails']);
                Route::put('/{order_id}/cancel', [OrderController::class, 'cancelOrderPayment']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'products'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [ProductController::class, 'getAllOwnedProducts'])->middleware(['pagination']);
                Route::get('/{product_id}/details', [ProductController::class, 'getProductDetails']);
                Route::put('/listing-status', [ProductController::class, 'updateListingStatuses']);
            }
        );
    }
);

// STORE
Route::group(
    ['prefix' => '/stores/{store_id}/'],
    function () {

        // PRODUCT_MANAGEMENT
        Route::group(
            ['prefix' => 'product-management'],
            function () {
                Route::get('/auction-lots/number', [ProductManagementController::class, 'getAllAuctionLotsAndNumber'])->middleware(['pagination']);

                Route::group(['middleware' => 'auth:api'], function () {
                    Route::get('/products/filter', [ProductManagementController::class, 'filterAuctionProductsByCategories'])->middleware(['pagination']);
                    Route::get('/products/filter/v2', [ProductManagementController::class, 'filterAuctionProductsByCategoriesV2'])->middleware(['pagination']);
                    Route::get('/related-products-urls', [ProductManagementController::class, 'getRelatedAuctionProductsUrls'])->middleware(['pagination']);
                    Route::get('/products/ids', [ProductManagementController::class, 'getAuctionProductsByIDs'])->name('paraqon.products.ids')->middleware(['pagination']);
                    Route::get('/products/{product_id}/details', [ProductManagementController::class, 'getProductDetails']);
                });
            }
        );

        // WISHLIST
        Route::group(
            ['prefix' => 'wishlist'],
            function () {
                Route::group(['middleware' => 'auth:api'], function () {
                    Route::get('/all', [ProductManagementController::class, 'getAllWishlistAuctionLots'])->middleware(['pagination']);
                });
            }
        );

        // Shopping Cart
        Route::group(
            ['prefix' => 'shopping-cart'],
            function () {
                Route::group(['middleware' => 'auth:api'], function () {
                    Route::post('/auction/all', [ShoppingCartController::class, 'getAllAuctionCartItems']);
                    Route::post('/auction/checkout', [ShoppingCartController::class, 'checkoutAuctionStore']);
                    Route::post('/main-store/all', [ShoppingCartController::class, 'getAllMainStoreCartItems']);
                    Route::post('/main-store/checkout', [ShoppingCartController::class, 'checkOutMainStore']);
                    Route::post('/checkout', [ShoppingCartController::class, 'checkOut']);
                });
            }
        );
    }
);

Route::group(
    ['prefix' => 'payments'],
    function () {
        Route::post('/callback', [PaymentController::class, 'onlinePaymentCallback']);
    }
);

Route::group(
    ['prefix' => 'watchlist'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/add-to-watchlist', [WatchlistItemController::class, 'addAndRemoveItem']);
                Route::get('/stores', [WatchlistItemController::class, 'getWatchedStores'])->middleware(['pagination']);
                Route::get('/auction-lots', [WatchlistItemController::class, 'getWatchedAuctionLots'])->middleware(['pagination']);
                Route::get('/compare', [WatchlistItemController::class, 'getCompareItems'])->middleware(['pagination']);
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
                Route::post('/{auction_registration_request_id}/register', [AuctionRegistrationRequestController::class, 'registerAuctionAgain']);
                Route::post('/{id}/deposit', [AuctionRegistrationRequestController::class, 'createDeposit']);
                Route::put('/{auction_registration_request_id}/archive', [AuctionRegistrationRequestController::class, 'archiveAuctionRegistrationRequest']);
                Route::get('/details', [AuctionRegistrationRequestController::class, 'getRegisteredAuctionDetails']);
            }
        );
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
                Route::put('/{id}/cancel', [DepositController::class, 'cancelDeposit']);
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
    ['prefix' => 'notifications'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [NotificationController::class, 'getAllNotifications'])->middleware(['pagination']);
                Route::put('/read', [NotificationController::class, 'markNotificationsAsRead']);
                Route::put('/{id}/delete', [NotificationController::class, 'deleteNotification']);
            }
        );
    }
);


Route::group(
    ['prefix' => 'services'],
    function () {
        Route::get('/time/now', [ServiceController::class, 'checkCurrentTime']);
        Route::get('/timezone/now', [ServiceController::class, 'checkOtherTimeZone']);
    }
);
