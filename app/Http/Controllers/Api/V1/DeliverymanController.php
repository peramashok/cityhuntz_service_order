<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\DeliveryMan;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class DeliverymanController extends Controller
{
   /**
    * get all delivery man list
    * 
    */
     public function get_delivery_man_list(Request $request){
        try{
            $vendor = auth()->user();
            if($vendor->role_id!=2){
                 return response()->json([
                    'status'=>'failed', 
                    'message'=>"You are not a restaurant’s owner"
                ], 400);
            }
            $restaurant=$vendor?->restaurants[0];
            $deliveryMen = DeliveryMan::with('last_location')->where('restaurant_id', $restaurant->id)->available()->active()->get();



            $deliveryMen = Helpers::deliverymen_list_formatting(data:$deliveryMen, restaurant_lat: $restaurant->latitude, restaurant_lng: $restaurant->longitude);

            return response()->json(['status'=>'success', 'data'=>$deliveryMen], 200);

        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }

    public function assign_deliveryman(Request $request){
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
            'order_id' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }
        $vendor = auth()->user();
        $restaurant=$vendor?->restaurants[0];


        $order= Order::where('id', $request->order_id)->where('restaurant_id', $restaurant->id)->with(['subscription.schedule_today','delivery_man'])->firstOrfail();
        if(!$order)
        {
            return response()->json([
                'errors'=>[
                    ['code'=>'order', 'message'=>translate('messages.order_not_found')]
                ]
            ],404);
        }

        $deliveryman = DeliveryMan::where('id' ,$request->delivery_man_id)->where('restaurant_id', $restaurant->id)->first();

        if ($order->delivery_man_id ==  $request->delivery_man_id) {
            return response()->json(['message' => translate('messages.order_already_assign_to_this_deliveryman')], 400);
        }

        if ($deliveryman) {
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                // $dm->decrement('assigned_order_count');
                $dm->save();
                // Send notification
            }
            $order->delivery_man_id = $request->delivery_man_id;
            $order->order_status = in_array($order->order_status, ['pending', 'confirmed']) ? 'accepted' : $order->order_status;
            $order->accepted = now();
            $order->save();
            OrderLogic::update_subscription_log($order);
            $deliveryman->current_orders = $deliveryman->current_orders + 1;
            $deliveryman->save();
            $deliveryman->increment('assigned_order_count');

            $value = Helpers::text_variable_data_format(value:Helpers::order_status_update_message('accepted',$order->customer? $order?->customer?->current_language_key:'en'),
            restaurant_name:$order->restaurant?->name,
            order_id:$order->id,
            user_name:"{$order?->customer?->f_name} {$order?->customer?->l_name}",
            delivery_man_name:"{$order?->delivery_man?->f_name} {$order?->delivery_man?->l_name}");

            try {
                // send notification

            } catch (\Exception $e) {
                info($e->getMessage());

                return response()->json([
                    'errors'=>[
                        ['code'=>'delivery_man', 'message'=>translate('messages.failed_to_assign_delivey_man')]
                    ]
                ],404);
            }

            return response()->json('success', 200);
        }

        return response()->json([
            'errors'=>[
                ['code'=>'delivery_man', 'message'=>translate('messages.delivery_man_not_found')]
            ]
        ],404);

    }


}
