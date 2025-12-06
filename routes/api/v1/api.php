<?php

use Illuminate\Support\Facades\Route;
use App\WebSockets\Handler\DMLocationSocketHandler;
use BeyondCode\LaravelWebSockets\Facades\WebSocketsRouter;
use App\Http\Controllers\Api\V1\UserProfileController;
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

Route::group(['namespace' => 'Api\V1', 'middleware'=>['localization','react']], function () {

   
    Route::group(['prefix' => 'member', 'middleware' => ['auth:api']], function () {
         Route::controller(VendorOrdersController::class)->group(function () {
             Route::post('restaurant/order-details/{restaurant}', 'getRestaurantOrder')->name('view-restaurant-order');
         });


         Route::prefix('order')->controller(OrdersController::class)->group(function () {
             Route::post('list', 'getAllCustomersOrders')->name('list');
             Route::get('details/{id}', 'getOrderDetails')->name('details');
         });
    });

   Route::group(['prefix' => 'vendor', 'middleware' => ['auth:api']], function () {
         Route::controller(VendorOrdersController::class)->group(function () {
            Route::get('order-details', 'get_order_details');
            Route::get('order', 'get_order');
            Route::get('current-orders', 'get_current_orders');
            Route::get('completed-orders', 'get_completed_orders');
            Route::get('all-orders', 'get_all_orders');
            Route::post('update-order-status', 'update_order_status');
            //get reserved orders
            Route::get('current_reserved_orders', 'getAllCurrentReservedBookATableOrders');
            Route::get('get_completed_reserved_orders', 'getAllCompletedReservedOrders');

            Route::get('all-reserved-orders', 'getAllReservedOrdersList');
        });
    });

    Route::group(['prefix' => 'delivery-man', 'middleware' => ['auth:api']], function () {
        Route::get('current-orders', 'OrdersController@get_current_orders');
    });


    Route::group(['prefix' => 'customer', 'middleware' => ['auth:api']], function () {
           
       
    });

    // Customer app
    // Route::group(['prefix' => 'customer'], function () {

     Route::group(['prefix' => 'customer', 'middleware' => 'apiGuestCheck'], function () {

        Route::prefix('order')->controller(OrdersController::class)->group(function () {
             Route::get('list', 'get_customer_order_list');
            // Route::get('order-subscription-list', 'OrderController@get_order_subscription_list');
            // Route::get('running-orders', 'OrderController@get_running_orders');
            Route::get('details', 'get_order_details');
            Route::post('place', 'place_order');  // visitor logs
            Route::post('cancel', 'cancel_order');
            // Route::post('refund-request', 'OrderController@refund_request');
            // Route::get('refund-reasons', 'OrderController@refund_reasons');
            Route::get('track', 'track_order');
            // Route::put('payment-method', 'OrderController@update_payment_method');
            // Route::put('offline-payment', 'OrderController@offline_payment');
            // Route::put('offline-payment-update', 'OrderController@update_offline_payment_info');
        });



        Route::prefix('cart')->controller(CartController::class)->group(function () {
            Route::get('list', 'get_carts');
            Route::post('add', 'add_to_cart');
            Route::post('update', 'update_cart');
            Route::delete('remove-item', 'remove_cart_item');
            Route::delete('remove', 'remove_cart');
            // Route::post('add-multiple', 'add_to_cart_multiple');
        });
    });
});

