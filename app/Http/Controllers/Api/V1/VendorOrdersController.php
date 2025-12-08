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

class VendorOrdersController extends Controller
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
                    $query->whereIn('order_status', ['confirmed','pending', 'processing', 'handover','picked_up','canceled','failed' ])
                    ->hasSubscriptionInStatus(['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed'])
                    ->orWhere(function($query){
                        $query->where('payment_status','paid')->where('order_status', 'accepted');
                    })
                    ->orWhere(function($query){
                        $query->where('order_status','pending')->whereIn('order_type', ['take_away' , 'dine_in', 'book_a_table']);
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
             return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500);
        }
    }

    /**
     * completed orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function get_completed_orders(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'limit' => 'required',
                'offset' => 'required',
                'status' => 'required' ,
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $vendor = auth()->user();
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
        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }

      /**
     * get orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function get_all_orders(Request $request)
    {
        try{
            $vendor = auth()->user();
            $orders = Order::whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor?->id);
            })
            ->with('customer')
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->NotDigitalOrder()
            ->get();
            $orders= Helpers::order_data_formatting(data:$orders,multi_data: true);
            return response()->json($orders, 200);
        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }

    /**
     * get order details
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function get_order(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }
            $vendor = auth()->user();

            $order = Order::whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor->id);
            })
            ->with(['customer','details','delivery_man','payments','OrderReference'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();
 
            return response()->json(Helpers::order_data_formatting($order),200);
         } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }

    
     /**
     * get order details
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
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }
            $vendor = auth()->user();

            $order = Order::whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor->id);
            })
            ->with(['customer','details','delivery_man','subscription','OrderReference'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();


             $order = Order::with(['customer','details','delivery_man','subscription','OrderReference', 'restaurant.vendor'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();

            if(is_null($order)){
                return response()->json(['status'=>'failed', 'message' =>'Order details not found' ],400);
            }
            if($order->restaurant?->vendor_id!=$vendor->id){
                 return response()->json(['status'=>'failed', 'message' =>"You can’t view other restaurant order details" ],400);
            }
            $details = $order?->details;
            $order['details'] = Helpers::order_details_data_formatting($details);
            return response()->json(['status'=>'success', 'order' => $order],200);
         } catch(\Extension $e){
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
            ], 500);
        }
    }


    /**
     * update order status
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function update_order_status(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required',
                'reason' =>'required_if:status,canceled',
                'status' => 'required|in:confirmed,processing,handover,delivered,canceled',
                'order_proof' =>'nullable|array|max:5',
            ]);
            $request->otp="123456";
            $validator->sometimes('otp', 'required', function ($request) {
                return (Config::get('order_delivery_verification')==1 && $request['status']=='delivered');
            });

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $vendor = auth()->user();
            $order = Order::whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor->id);
            })
            ->where('id', $request['order_id'])->with(['subscription_logs','details'])
            ->Notpos()
            ->first();

            if(!$order)
            {
                return response()->json([
                    'status'=>'failed',
                    'errors' => [
                        ['code' => 'order', 'message' => translate('messages.Order_not_found')]
                    ]
                ], 403);
            }

            if($request['order_status']=='canceled')
            {
                if(!config('canceled_by_restaurant'))
                {
                    return response()->json([
                        'status'=>'failed',
                        'errors' => [
                            ['code' => 'status', 'message' => translate('messages.you_can_not_cancel_a_order')]
                        ]
                    ], 403);
                }
                else if($order->confirmed)
                {
                    return response()->json([
                        'status'=>'failed',
                        'errors' => [
                            ['code' => 'status', 'message' => translate('messages.you_can_not_cancel_after_confirm')]
                        ]
                    ], 403);
                }
            }

            $restaurant=$vendor?->restaurants[0];
            $data =0;
            if (($restaurant?->restaurant_model == 'subscription' &&  $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant?->restaurant_model == 'commission' &&  $restaurant?->self_delivery_system == 1) ){
             $data =1;
            }

            if($request['status'] =="confirmed" && !$data && config('order_confirmation_model') == 'deliveryman' && !in_array($order['order_type'],['dine_in','take_away']) && $order->subscription_id == null)
            {
                return response()->json([
                    'status'=>'failed',
                    'errors' => [
                        ['code' => 'order-confirmation-model', 'message' => translate('messages.order_confirmation_warning')]
                    ]
                ], 403);
            }

            if($order->picked_up != null)
            {
                return response()->json([
                    'status'=>'failed',
                    'errors' => [
                        ['code' => 'status', 'message' => translate('messages.You_can_not_change_status_after_picked_up_by_delivery_man')]
                    ]
                ], 403);
            }

            if($request['status']=='delivered' && !in_array($order['order_type'],['dine_in','take_away']) && !$data)
            {
                return response()->json([
                    'status'=>'failed',
                    'errors' => [
                        ['code' => 'status', 'message' => translate('messages.you_can_not_delivered_delivery_order')]
                    ]
                ], 403);
            }
            // if(Config::get('order_delivery_verification')==1 && $request['status']=='delivered' && $order->otp != $request['otp'])
            // {
            //     return response()->json([
            //         'status'=>'failed',
            //         'errors' => [
            //             ['code' => 'otp', 'message' => 'Not matched']
            //         ]
            //     ], 403);
            // }

            if ($request->status == 'delivered' && ($order->transaction == null || isset($order->subscription_id))) {

                if(isset($order->subscription_id) && count($order->subscription_logs) == 0 ){
                    return response()->json([
                        'status'=>'failed',
                        'errors' => [
                            ['code' => 'order-subscription', 'message' => translate('messages.You_Can_Not_Delivered_This_Subscription_order_Before_Schedule')]
                        ]
                    ], 403);
                }

                $unpaid_payment = OrderPayment::where('payment_status','unpaid')->where('order_id',$order->id)->first()?->payment_method;
                $unpaid_pay_method = 'digital_payment';
                if($unpaid_payment){
                    $unpaid_pay_method = $unpaid_payment;
                }

                if($order->payment_method == 'cash_on_delivery'|| $unpaid_pay_method == 'cash_on_delivery')
                {
                    $ol = OrderLogic::create_transaction( order:$order, received_by:'restaurant', status: null);
                }
                else
                {
                    $ol = OrderLogic::create_transaction( order:$order, received_by:'admin', status: null);
                }

                if(!$ol){
                    return response()->json([
                        'status'=>'failed',
                        'errors' => [
                            ['code' => 'error', 'message' => translate('messages.faield_to_create_order_transaction')]
                        ]
                    ], 406);
                }

                $order->payment_status = 'paid';
                OrderLogic::update_unpaid_order_payment(order_id:$order->id, payment_method:$order->payment_method);
            }

            if($request->status == 'delivered')
            {
                $order?->details?->each(function($item, $key){
                    $item?->food?->increment('order_count');
                });
                if($order->is_guest == 0){
                    $order->customer->increment('order_count');
                }
                $order?->restaurant?->increment('order_count');

                if($order?->delivery_man)
                {
                    $dm = $order->delivery_man;
                    $dm->current_orders = $dm->current_orders>1?$dm->current_orders-1:0;
                    $dm->save();
                }
                $img_names = [];
                $images = [];
                if (!empty($request->file('order_proof'))) {
                    foreach ($request->order_proof as $img) {
                        // $image_name = Helpers::upload('order/', 'png', $img);
                        $url = env('OBJECT_APIURL');
                        $modifiedUrl = str_replace('/api/v1', '', $url);
                        $file = $request->file('image');
                        $imagePhotoUrl = "";
                        $imageDocuUrl = "";
                         
                        $image_profile_pic = rand() . '.' . $file->getClientOriginalExtension();
                        $relativePath = "orders" . "/" . date("Y") . "/" . date("M") . "/" . $image_profile_pic;
                        $imageRespose = Helpers::imageUploadToDrive($file, null, $relativePath, $image_profile_pic);
                        if($imageRespose['success']){
                            $image_name = $imageRespose['url'];
                        } else {
                            return response()->json([
                                'status' => 'failed',
                                'message' => $imageRespose['message']
                            ], 400);  
                        }

                        array_push($img_names, ['img'=>$image_name, 'storage'=> Helpers::getDisk()]);
                    }
                    $images = $img_names;
                }
                $order->order_proof = json_encode($images);
            }


            if($request->status == 'canceled')
            {
                if($order?->delivery_man)
                {
                    $dm = $order->delivery_man;
                    $dm->current_orders = $dm->current_orders>1?$dm->current_orders-1:0;
                    $dm->save();
                }
                if(!isset($order->confirmed) && isset($order->subscription_id)){
                    $order?->subscription()?->update(['status' => 'canceled']);
                        if($order?->subscription?->log){
                            $order?->subscription?->log()?->update([
                                'order_status' => $request->status,
                                'canceled' => now(),
                                ]);
                        }
                }
                $order->cancellation_reason=$request->reason;
                $order->canceled_by='restaurant';

                Helpers::decreaseSellCount(order_details:$order->details);

            }

            if($request->status == 'processing') {
                $order->processing_time = isset($request->processing_time) ? $request->processing_time : explode('-', $order['restaurant']['delivery_time'])[0];
            }
            $order->order_status = $request['status'];
            $order[$request['status']] = now();
            $order->save();
           // Helpers::send_order_notification($order);

            return response()->json(['status'=>'success','message' => 'Status updated'], 200);

        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }

    /**
     * get all orders based on the restaurant id
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function getRestaurantOrder(Request $request, $restaurantId)
    {
        try{

            $restaurant=Restaurant::where('id', $restaurantId)->first();
            if(is_null($restaurant)){

            }

            $list=Order::where('restaurant_id', $restaurantId)->with('customer');
            if($request->has('search')){
               $key = explode(' ', $request['search']);
               $list=$list->when(isset($key) , function ($q) use($key){
                    $q->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                           $q->orWhere('id', 'like', "%{$value}%");
                        }
                    });
                });
            }

            $pageno = 0; $pagelength = 10; 
            $totalrecords = $list->count();
            if ($request->filled('pagelength') && $request->filled('pageno')) {
                $pagelength = $request->pagelength;
                $pageno = $request->pageno;
            }      
            $list = $list->latest()->Notpos()
                 ->skip(($pageno - 1) * $pagelength)
                 ->take($pagelength)
                 ->get();
 

            $data['all_order_counts']=Order::where('restaurant_id', $restaurantId)->Notpos()->count();
            $data['scheduled_orders']=Order::Scheduled()->Notpos()->where('restaurant_id', $restaurantId)->count();
            $data['pending_orders']=Order::where(['order_status'=>'pending','restaurant_id'=>$restaurantId])->Notpos()->count();
            $data['delivered_orders']=Order::where(['order_status'=>'delivered', 'restaurant_id'=>$restaurantId])->Notpos()->count();
            $data['cancelled_orders']=Order::where(['order_status'=>'canceled', 'restaurant_id'=>$restaurantId])->count();
            $data['data'] = $list;
            $data['current_page'] =$pageno ? $pageno : '1';
            $data['total'] = $totalrecords;
            $data['per_page'] = $pagelength ? $pagelength : '10';
            return response()->json(['status' => 'success', 'data' => $data], 200);
            
        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }



    /**
     * completed orders list for ve
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function getAllCurrentReservedBookATableOrders(Request $request)
    {
        try{
            $vendor = auth()->user();

            $restaurant=$vendor?->restaurants[0];
            $data =0;
            if (($restaurant?->restaurant_model == 'subscription' && $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant?->restaurant_model == 'commission' &&  $restaurant?->self_delivery_system == 1) ){
             $data =1;
            }
            $orders = Order::where('order_type', 'book_a_table')->whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor->id);
            })
            ->with('customer')
            ->where(function($query)use($data){
                if(config('order_confirmation_model') == 'restaurant' || $data)
                {
                    $query->whereIn('order_status', ['confirmed', 'processing', 'handover','picked_up','canceled','failed' ])
                    ->hasSubscriptionInStatus(['confirmed', 'processing', 'handover','picked_up','canceled','failed' ]);
                }
                else
                {
                    $query->whereIn('order_status', ['confirmed', 'processing', 'handover','picked_up','canceled','failed' ])
                    ->hasSubscriptionInStatus(['confirmed', 'processing', 'handover','picked_up','canceled','failed'])
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
     * get all completed reserved orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
     public function getAllCompletedReservedOrders(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'limit' => 'required',
                'offset' => 'required',
                'status' => 'required' ,
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $vendor = auth()->user();
            $paginator = Order::where('order_type', 'book_a_table')->whereHas('restaurant.vendor', function($query) use($vendor){
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
     * get all reserved orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function getAllReservedOrdersList(Request $request)
    {
        try{
            $vendor = auth()->user();
            $paginator = Order::where('order_type', 'book_a_table')->whereHas('restaurant.vendor', function($query) use($vendor){
                $query->where('id', $vendor?->id);
            })
            ->with('customer')
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->NotDigitalOrder()
             ->paginate($request['limit'], ['*'], 'page', $request['offset']);
            $orders= Helpers::order_data_formatting($paginator->items(), true);
            return response()->json(['status'=>'success', 'data'=>$orders], 200);
        } catch(\Extension $e){
             return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500);
        }
    }
}
