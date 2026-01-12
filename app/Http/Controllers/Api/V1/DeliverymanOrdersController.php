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
use App\CentralLogics\OrderLogic;
use Illuminate\Support\Facades\Config;
use App\Models\DeliveryHistory;

class DeliverymanOrdersController extends Controller
{
    

    /**
     * get all current orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function get_current_orders(Request $request)
    {
        try{
            $dm = auth()->user();
            $orders = Order::with(['customer', 'restaurant'])
            ->whereIn('order_status', ['accepted','confirmed','pending', 'processing', 'picked_up', 'handover'])
            ->where(['delivery_man_id' => $dm->id])
            ->where('order_type', 'delivery')
            ->orderBy('accepted')
            ->orderBy('schedule_at', 'desc')
            ->Notpos()
            ->get();

            $orders= Helpers::order_data_formatting($orders, true);

            return response()->json(['status'=>'success', 'data'=>$orders], 200);

        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }

     /**
     * get all latest orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function get_latest_orders(Request $request)
    {
        try{
            $dm = auth()->user();

            $orders = Order::where('order_type', 'delivery')->with(['customer', 'restaurant']);

            if($dm->type == 'zone_wise')
                {
                    $orders = $orders
                    ->whereHas('restaurant', function($q) use($dm){
                        $q->where('restaurant_model','subscription')->where('zone_id', $dm->zone_id)->whereHas('restaurant_sub', function($q1){
                            $q1->where('self_delivery', 0);
                        });
                    })
                    ->orWhereHas('restaurant', function($qu) use($dm) {
                        $qu->where('restaurant_model','commission')->where('zone_id', $dm->zone_id)->where('self_delivery_system', 0);
                    });
                }
                else
                {
                    $orders = $orders->where('restaurant_id', $dm->restaurant_id);
                }

            if(config('order_confirmation_model') == 'deliveryman' && $dm->type == 'zone_wise')
            {
                $orders = $orders->whereIn('order_status', ['pending', 'confirmed','processing','handover']);
            }
            else
            {
                $orders = $orders->where(function($query){
                    $query->where(function($query){
                        $query->where('order_status', 'pending')->whereNotNull('subscription_id');
                    })->orWhereIn('order_status', ['confirmed','processing','handover']);
                });
            }

            if(isset($dm->vehicle_id )){
                $orders = $orders->where('vehicle_id',$dm->vehicle_id);
            }

            $orders = $orders->delivery()
            ->OrderScheduledIn(30)
            ->NotDigitalOrder()
            ->whereNull('delivery_man_id')
            ->orderBy('schedule_at', 'desc')
            ->Notpos()
            ->get();

            $orders= Helpers::order_data_formatting($orders, true);

            return response()->json(['status'=>'success', 'data'=>$orders], 200);
       } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }


     /**
     * get all latest orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function get_all_orders(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'limit' => 'required',
                'offset' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }

            $dm = auth()->user();

            $paginator = Order::where('order_type', 'delivery')->with(['customer', 'restaurant'])
            ->whereIn('order_status', ['delivered','canceled','refund_requested','refunded','refund_request_canceled','failed'])
            ->where(function($q)use($dm){
                $q->where('delivery_man_id', $dm->id)
                ->orWhereHas('subscription_logs',function($query)use($dm){
                    $query->where('delivery_man_id', $dm->id)->whereIn('order_status', ['delivered','canceled','refund_requested','refunded','refund_request_canceled','failed']);
                });
            })

            ->orderBy('schedule_at', 'desc')
            ->Notpos()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);
            $orders= Helpers::order_data_formatting($paginator->items(), true);
            $data = [
                'total_size' => $paginator->total(),
                'limit' => $request['limit'],
                'offset' => $request['offset'],
                'orders' => $orders
            ];
            return response()->json(['status'=>'success', 'data'=$data], 200);
        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }

    /**
     * get all delivery history orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */

    public function get_order_history(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required'
            ]);
            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            $dm = auth()->user();

            $history = DeliveryHistory::where(['order_id' => $request['order_id'], 'delivery_man_id' => $dm->id])->get();
            return response()->json(['status'=>'success', 'data'=>$history], 200);
        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }


    /**
     * accept order by delivery man
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function accept_order(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|exists:orders,id',
            ]);
            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            $dm=DeliveryMan::where(['auth_token' => $request['token']])->first();
            $order = Order::where('order_type', 'delivery')->where('id', $request['order_id'])
            ->whereNull('delivery_man_id')
            ->Notpos()
            ->first();
            if(!$order)
            {
                return response()->json([
                    'status'=>'failed',
                    'code' => 'order', 
                    'message' => translate('messages.can_not_accept')
                ], 400);
            }
            if($dm->current_orders >= config('dm_maximum_orders'))
            {
                return response()->json([
                    'status'=>'failed',
                    'code' => 'dm_maximum_order_exceed', 
                    'message'=> translate('messages.dm_maximum_order_exceed_warning')
                ], 405);
            }

            $cash_in_hand =$dm?->wallet?->collected_cash ?? 0;


            $dm_max_cash_in_hand=  BusinessSetting::where('key','dm_max_cash_in_hand')->first()?->value ?? 0;

            if($order->payment_method == "cash_on_delivery" && (($cash_in_hand+$order->order_amount) >= $dm_max_cash_in_hand)){
                return response()->json(['errors' => Helpers::error_formater('dm_max_cash_in_hand',translate('delivery man max cash in hand exceeds'))], 203);
            }

            $order->order_status = in_array($order->order_status, ['pending', 'confirmed'])?'accepted':$order->order_status;
            $order->delivery_man_id = $dm->id;
            $order->accepted = now();
            $order->save();

            $dm->current_orders = $dm->current_orders+1;
            $dm->save();

            $dm->increment('assigned_order_count');

            

    
            OrderLogic::update_subscription_log($order);

            //send notifications
           // $fcm_token= ($order->is_guest == 0 ? $order?->customer?->cm_firebase_token : $order?->guest?->fcm_token) ?? null;

             //  $value = Helpers::text_variable_data_format(value:Helpers::order_status_update_message('accepted',$order->customer?$order?->customer?->current_language_key:'en'),restaurant_name:$order->restaurant?->name,order_id:$order->id,user_name:"{$order?->customer?->f_name} {$order?->customer?->l_name}",delivery_man_name:"{$order->delivery_man?->f_name} {$order->delivery_man?->l_name}");

            // try {
            //     $customer_push_notification_status=Helpers::getNotificationStatusData('customer','customer_order_notification');
            //     if(  $customer_push_notification_status?->push_notification_status  == 'active' && $value && $fcm_token)
            //     {
            //         $data = [
            //             'title' =>translate('messages.order_push_title'),
            //             'description' => $value,
            //             'order_id' => $order['id'],
            //             'image' => '',
            //             'type'=> 'order_status'
            //         ];
            //         Helpers::send_push_notif_to_device($fcm_token, $data);
            //     }

            // } catch (\Exception $e) {
            //     info($e->getMessage());
            // }

            return response()->json(['status'=>'success', 'message' => translate('Order accepted successfully')], 200);

        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }


    /**
     * update order status by delivery man 
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function update_order_status(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required',
                'status' => 'required|in:confirmed,canceled,picked_up,delivered',
                'reason' =>'required_if:status,canceled',
                'order_proof' =>'nullable|array|max:5',
            ]);

            // $validator->sometimes('otp', 'required', function ($request) {
            //     return (Config::get('order_delivery_verification')==1 && $request['status']=='delivered');
            // });

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }
            $dm = auth()->user();
            $order = Order::where('order_type', 'delivery')->where('id', $request['order_id'])->with(['subscription_logs','details'])->first();
            if(!isset($order)){
                return response()->json([
                    'status'=>'failed',
                    'code' => 'order-not-found', 
                    'message' => translate('messages.order_not_found')
                ], 403);
            }

            if($request['status'] =="confirmed" && config('order_confirmation_model') == 'restaurant')
            {
                return response()->json([
                    'status'=>'failed', 
                    'code' => 'order-confirmation-model', 
                    'message' => translate('messages.order_confirmation_warning')
                ], 403);
            }

            if($request['status'] == 'canceled' && !config('canceled_by_deliveryman'))
            {
                return response()->json([
                    'status'=>'failed', 
                    'code' => 'status', 
                    'message' => translate('messages.you_can_not_cancel_a_order')
                ], 403);
            }

            if(isset($order->confirmed ) && $request['status'] == 'canceled')
            {
                return response()->json([
                    'status'=>'failed',
                    'code' => 'delivery-man', 
                    'message' => translate('messages.order_can_not_cancle_after_confirm')
                ], 403);
            }

            // if(Config::get('order_delivery_verification')==1 && $request['status']=='delivered' && $order->otp != $request['otp'])
            // {
            //     return response()->json([
            //         'status'=>'failed',
            //        'code' => 'otp', 
            //        'message' => translate('Not matched')
            //     ], 406);
            // }
            if ($request->status == 'delivered' || isset($order->subscription_id))
            {
                if(isset($order->subscription_id) && count($order->subscription_logs) == 0 ){
                    return response()->json([
                        'status'=>'failed',
                        'code' => 'order-subscription', 
                        'message' => translate('messages.You_Can_Not_Delivered_This_Subscription_order_Before_Schedule')
                    ], 403);
                }

                if($order->transaction == null)
                {
                    $unpaid_payment = OrderPayment::where('payment_status','unpaid')->where('order_id',$order->id)->first();
                    $pay_method = 'digital_payment';
                    if($unpaid_payment && $unpaid_payment->payment_method == 'cash_on_delivery'){
                        $pay_method = 'cash_on_delivery';
                    }
                    $reveived_by = ($order->payment_method == 'cash_on_delivery' || $pay_method == 'cash_on_delivery') ?($dm->type != 'zone_wise'?'restaurant':'deliveryman'):'admin';

                    if(OrderLogic::create_transaction(order:$order,received_by: $reveived_by, status:null))
                    {
                        $order->payment_status = 'paid';
                    }
                    else
                    {
                        return response()->json([
                           'status'=>'failed',
                           'code' => 'error', 
                           'message' => translate('messages.faield_to_create_order_transaction')
                        ], 406);
                    }
                }
                if($order->transaction)
                {
                    $order->transaction->update(['delivery_man_id'=>$dm->id]);
                }

                $order?->details->each(function($item, $key){
                    if($item->food)
                    {
                        $item->food->increment('order_count');
                    }
                });
                $order?->customer?->increment('order_count') ?? '';

                $dm->current_orders = $dm->current_orders>1?$dm->current_orders-1:0;
                $dm->save();

                $dm->increment('order_count');
                $order->restaurant->increment('order_count');


                $img_names = [];
                $images = [];
                if (!empty($request->file('order_proof'))) {
                    foreach ($request->order_proof as $img) {
                        $image_name = Helpers::upload('order/', 'png', $img);
                        array_push($img_names, ['img'=>$image_name, 'storage'=> Helpers::getDisk()]);
                    }
                    $images = $img_names;
                }
                $order->order_proof = json_encode($images);

                OrderLogic::update_unpaid_order_payment(order_id:$order->id, payment_method:$order->payment_method);

            }
            else if($request->status == 'canceled')
            {
                if($order->delivery_man)
                {
                    $dm = $order->delivery_man;
                    $dm->current_orders = $dm->current_orders>1?$dm->current_orders-1:0;
                    $dm->save();
                }


                if(!isset($order->confirmed) && isset($order->subscription_id)){
                    $order?->subscription()?->update(['status' => 'canceled']);
                    if($order?->subscription?->log){
                        $order->subscription->log()->update([
                            'order_status' => $request->status,
                            'canceled' => now(),
                            ]);
                    }
                }

                $order->cancellation_reason = $request->reason;
                $order->canceled_by = 'deliveryman';


                Helpers::decreaseSellCount(order_details:$order->details);

            }

            if($request->status == 'confirmed' &&  $order->delivery_man_id == null){
                $order->delivery_man_id = $dm->id;
            }
            // dd($request['status']);
            $order->order_status = $request['status'];
            $order[$request['status']] = now();
            $order->save();

            // send notifications
            try{
                $response = Http::post(
                    env('NOTIFICATION_URL') . 'notifications/update_status',
                    [
                        'order_id' => $singleOrder->id,
                        'user_type' => 'deliveryman',
                        'status'=>$request['status'],
                        'status_changed_by'=>auth()->user()->id
                    ]
                );
            }catch(\Exception $ex){
                  \Log::error('Notification API failed', [
                        'message' => $ex->getMessage(),
                        'order_id' => $singleOrder->id,
                    ]); 

               // echo $ex->getMessage();
            }

            OrderLogic::update_subscription_log($order);
            return response()->json([
                'status'=>'success', 
                'message' => translate('Status updated')
            ], 200);
        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }

    /**
     * update order payment status by delivery man 
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */

     public function order_payment_status_update(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required',
                'status' => 'required|in:paid'
            ]);
            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }

            $dm = auth()->user();

            if (Order::where(['delivery_man_id' => $dm->id, 'id' => $request['order_id']])->Notpos()->first()) {
                Order::where(['delivery_man_id' => $dm->id, 'id' => $request['order_id']])->update([
                    'payment_status' => $request['status']
                ]);
                return response()->json(['status'=>'success', 'message' => translate('Payment status updated')], 200);
            }
            return response()->json([
                'status'=>'failed', 
                'code' => 'order', 
                'message' => translate('not found')
            ], 400);

        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }

    /**
     * update order payment status by delivery man 
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
     public function get_order_details(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required'
            ]);
            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            // OrderLogic::create_subscription_log($request->order_id);
            $dm = auth()->user();
            $order = Order::where('order_type', 'delivery')->with(['details'])->where('id',$request['order_id'])->where(function($query) use($dm){
                $query->whereNull('delivery_man_id')
                    ->orWhere('delivery_man_id', $dm->id);
            })->Notpos()->first();
            if(!$order)
            {
                return response()->json([
                    'status'=>'failed', 
                    'code' => 'order', 
                    'message' => translate('messages.not_found')
                ], 400);
            }
            $details = Helpers::order_details_data_formatting($order->details);

            return response()->json(['status'=>'success', 'data'=>$details], 200);
        } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }


    public function get_order(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }
            $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

            $order = Order::where('order_type', 'delivery')->with(['customer', 'restaurant','details','payments'])->where('id', $request['order_id'])
            ->where(function($q)use($dm){
                $q->where('delivery_man_id', $dm->id)
                ->orWhereHas('subscription_logs',function($query)use($dm){
                    $query->where('delivery_man_id', $dm->id)->whereIn('order_status', ['delivered','canceled','refund_requested','refunded','refund_request_canceled','failed']);
                });
            })
            ->Notpos()->first();
            if(!$order)
            {
                return response()->json([
                    'status' => 'failed',
                    'code' => 'order', 
                    'message' => translate('messages.not_found')
                ], 404);
            }
            return response()->json([ 'status' => 'success', 'data'=>Helpers::order_data_formatting($order)], 200);
         } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }
}
