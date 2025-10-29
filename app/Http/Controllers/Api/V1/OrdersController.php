<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Route;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Models\SubscriptionBillingAndRefundHistory;
use App\Models\Restaurant;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\SubscriptionTransaction;

class OrdersController extends Controller
{
    /**
     * completed orders list for ve
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function get_current_orders(Request $request)
    {
        try{
            $vendor = auth()->user();

            $restaurant=$vendor?->restaurants[0];
            $data =0;
            if (($restaurant?->restaurant_model == 'subscription' && $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant?->restaurant_model == 'commission' &&  $restaurant?->self_delivery_system == 1) ){
             $data =1;
            }
            $orders = Order::whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor->id);
            })
            ->with('customer')
            ->where(function($query)use($data){
                if(config('order_confirmation_model') == 'restaurant' || $data)
                {
                    $query->whereIn('order_status', ['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed' ])
                    ->hasSubscriptionInStatus(['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed' ]);
                }
                else
                {
                    $query->whereIn('order_status', ['confirmed', 'processing', 'handover','picked_up','canceled','failed' ])
                    ->hasSubscriptionInStatus(['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed'])
                    ->orWhere(function($query){
                        $query->where('payment_status','paid')->where('order_status', 'accepted');
                    })
                    ->orWhere(function($query){
                        $query->where('order_status','pending')->whereIn('order_type', ['take_away' , 'dine_in']);
                    });
                }
            })
            ->NotDigitalOrder()
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->get();
            $orders= Helpers::order_data_formatting($orders, true);
            return response()->json($orders, 200);
        } catch(\Extension $e){

        }
    }

    /**
     * completed orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function get_completed_orders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
            'status' => 'required' ,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $vendor = $request['vendor'];
        $paginator = Order::whereHas('restaurant.vendor', function($query) use($vendor){
            $query->where('id', $vendor->id);
        })
        ->with('customer','refund')
        ->when($request->status == 'all', function($query){
            return $query->whereIn('order_status', ['refunded','refund_requested','refund_request_canceled', 'delivered','canceled','failed' ]);
        })
        ->when($request->status != 'all', function($query)use($request){
            return $query->where('order_status', $request->status);
        })
        ->Notpos()
        ->latest()
        ->paginate($request['limit'], ['*'], 'page', $request['offset']);
        $orders= Helpers::order_data_formatting($paginator->items(), true);
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }
}
