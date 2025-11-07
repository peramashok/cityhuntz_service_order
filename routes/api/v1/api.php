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
          
     
 
    
    });

   Route::group(['prefix' => 'vendor', 'middleware' => ['auth:api']], function () {

         Route::controller(VendorOrdersController::class)->group(function () {
            Route::get('order-details', 'get_order_details');
            Route::get('order', 'get_order');
            Route::get('current-orders', 'get_current_orders');
            Route::get('completed-orders', 'get_completed_orders');
            Route::get('all-orders', 'get_all_orders');
            Route::post('update-order-status', 'update_order_status');
        });
    });

    Route::group(['prefix' => 'delivery-man', 'middleware' => ['auth:api']], function () {
          
        Route::get('current-orders', 'OrdersController@get_current_orders');
       
    });


    Route::group(['prefix' => 'customer', 'middleware' => ['auth:api']], function () {
           
       
    });
});

