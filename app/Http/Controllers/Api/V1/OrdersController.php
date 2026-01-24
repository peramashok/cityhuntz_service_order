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
use App\CentralLogics\OrderLogic;
use App\CentralLogics\CouponLogic;
use App\Models\BusinessSetting;
use App\Models\SubscriptionBillingAndRefundHistory;
use App\Models\Restaurant;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\SubscriptionTransaction;
use App\Models\Coupon;
use App\Models\Zone;
use App\Models\ItemCampaign;
use App\Models\Food;
use App\Models\CashBack;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use App\Models\DMReview;
use App\Models\Review;
use App\Models\SubscriptionSchedule;
use App\Models\DeliveryMan;
use App\Models\Category;
use Carbon\Carbon;
use App\Models\PaymentSetting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

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
            // $orders = Order::whereHas('restaurant.vendor', function($query) use($vendor){
            //     $query->where('id', $vendor->id);
            // })
            // ->with('customer')
            // ->where(function($query)use($data){
            //     if(config('order_confirmation_model') == 'restaurant' || $data)
            //     {
            //         $query->whereIn('order_status', ['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed' ])
            //         ->hasSubscriptionInStatus(['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed' ]);
            //     }
            //     else
            //     {
            //         $query->whereIn('order_status', ['confirmed', 'processing', 'handover','picked_up','canceled','failed' ])
            //         ->hasSubscriptionInStatus(['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed'])
            //         ->orWhere(function($query){
            //             $query->where('payment_status','paid')->where('order_status', 'accepted');
            //         })
            //         ->orWhere(function($query){
            //             $query->where('order_status','pending')->whereIn('order_type', ['delivery', 'book_a_table', 'take_away' , 'dine_in']);
            //         });
            //     }
            // })
            // ->NotDigitalOrder()
            // ->Notpos()
            // ->orderBy('schedule_at', 'desc')
            // ->get();


            $orders = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
                $query->where('id', $vendor->id);
            })
            ->with('customer')
            ->where(function ($query) use ($data) {

                // ✅ MAIN CONDITIONS
                if (config('order_confirmation_model') == 'restaurant' || $data) {

                    $query->where(function ($q) {
                        $q->whereIn('order_status', [
                            'accepted',
                            'pending',
                            'confirmed',
                            'processing',
                            'handover',
                            'picked_up',
                            'canceled',
                            'failed'
                        ]);
                    });

                } else {

                    // ✅ Orders that REQUIRE subscription
                    $query->where(function ($q) {
                        $q->whereIn('order_status', [
                            'confirmed',
                            'processing',
                            'handover',
                            'picked_up',
                            'canceled',
                            'failed'
                        ])
                        ->hasSubscriptionInStatus([
                            'accepted',
                            'pending',
                            'confirmed',
                            'processing',
                            'handover',
                            'picked_up',
                            'canceled',
                            'failed'
                        ]);
                    })

                    // ✅ Accepted & Paid (no subscription block)
                    ->orWhere(function ($q) {
                        $q->where('order_status', 'accepted')
                          ->where('payment_status', 'paid');
                    })

                    // ✅ ✅ Pending orders (THIS WAS THE ISSUE)
                    ->orWhere(function ($q) {
                        $q->where('order_status', 'pending')
                          ->whereIn('order_type', [
                              'delivery',
                              'book_a_table',
                              'take_away',
                              'dine_in'
                          ]);
                    });
                }
            })
            ->NotDigitalOrder()
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->get();

            //$orders= Helpers::order_data_formatting($orders, true);
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

     /**
     * cancel placed order
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
     public function cancel_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|max:255',
            'guest_id' => $request->user ? 'nullable' : 'required',
            'order_id'=>'required|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
        }
        try{
            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $order = Order::where(['user_id' => $user_id, 'id' => $request['order_id']])

            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->with('details')
            ->Notpos()->first();
            if(!$order){
                    return response()->json(['status'=>'failed', 'code' => 'order', 'message' => translate('messages.not_found')], 400);
            }
            else if ($order->order_status == 'pending' || $order->order_status == 'failed' || $order->order_status == 'canceled'  ) {
                $order->order_status = 'canceled';
                $order->canceled = now();
                $order->cancellation_reason = $request->reason;
                $order->canceled_by = 'customer';
                $order->save();

                Helpers::decreaseSellCount(order_details:$order->details);
                Helpers::increment_order_count($order->restaurant); //for subscription package order increase

                //Refund amount
                try {
                    $response = Http::post(
                        env('PAYMENT_URL') . 'refunds/order_refund',
                        [
                            'order_id' => $order->id,
                            'amount'=>$order->order_amount,
                            'reason'=>$request->reason
                        ]
                    );
                } catch (\Exception $th) {
                    Log::error($ex->getMessage());
                }
                //send notification
                try{
                    $response = Http::post(
                        env('NOTIFICATION_URL') . 'notifications/update_status',
                        [
                            'order_id' => $order->id,
                            'user_type'=>'customer',
                            'status'=>'canceled'
                        ]
                    );
                }catch(\Exception $ex){
                    \Log::error('Notification API failed', [
                        'message' => $ex->getMessage(),
                        'order_id' => $order->id,
                    ]); 
                }

                return response()->json(['status'=>'success', 'message' => translate('messages.order_canceled_successfully')], 200);
            }
            return response()->json(['status'=>'failed', 'code' => 'order', 'message' => translate('messages.you_can_not_cancel_after_confirm')], 400);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }

    public function place_order(Request $request)
    {
        try{
              $validator = Validator::make($request->all(), [
                'order_amount' => 'required',
                'payment_method' => 'required|in:cash_on_delivery,digital_payment,wallet,offline_payment',
                'order_type' => 'required|in:take_away,dine_in,delivery',
                'restaurant_id' => 'required|array|min:1',
                'restaurant_id.*' => 'integer|exists:restaurants,id',
                'distance' => 'required_if:order_type,delivery',
                'address' => 'required_if:order_type,delivery',
                'longitude' => 'required_if:order_type,delivery',
                'latitude' => 'required_if:order_type,delivery',
                'dm_tips' => 'nullable|numeric',
                'guest_id' => $request->user ? 'nullable' : 'required',
                'contact_person_name' => $request->user ? 'nullable' : 'required',
                'contact_person_number' => $request->user ? 'nullable' : 'required'
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            if($request->payment_method == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1)
            {
                return response()->json( ['status'=>'failed', 'code' => 'payment_method', 'message' => translate('messages.customer_wallet_disable_warning')], 203);
            }

            if($request->partial_payment && Helpers::get_mail_status('partial_payment_status') == 0){
                return response()->json( ['status'=>'failed','code' => 'order_method', 'message' => translate('messages.partial_payment_is_not_active')], 403);
            }

            if ($request->payment_method == 'offline_payment' &&  Helpers::get_mail_status('offline_payment_status') == 0) {
                return response()->json( ['status'=>'failed', 'code' => 'offline_payment_status', 'message' => translate('messages.offline_payment_for_the_order_not_available_at_this_time')], 403);
            }

            $digital_payment = Helpers::get_business_settings('digital_payment');
            if($digital_payment['status'] == 0 && $request->payment_method == 'digital_payment'){
                return response()->json( ['status'=>'failed','code' => 'digital_payment', 'message' => translate('messages.digital_payment_for_the_order_not_available_at_this_time')], 403);
            }

            if($request->is_guest && !Helpers::get_mail_status('guest_checkout_status')){
                return response()->json(['status'=>'failed', 'code' => 'is_guest', 'message' => translate('messages.Guest_order_is_not_active')], 403);
            }

            $coupon = null;
            $delivery_charge = null;
            $free_delivery_by = null;
            $coupon_created_by = null;
            $schedule_at =$request->schedule_at?\Carbon\Carbon::parse($request->schedule_at):now();
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
            $maximum_shipping_charge =  0;
            $max_cod_order_amount_value=  0;
            $increased=0;
            $distance_data = $request->distance ?? 0;


            $dataArray['coupon'] = $coupon;
            $dataArray['delivery_charge'] = $delivery_charge;
            $dataArray['free_delivery_by'] = $free_delivery_by;
            $dataArray['coupon_created_by'] = $coupon_created_by;
            $dataArray['schedule_at'] =$schedule_at;
            $dataArray['per_km_shipping_charge'] = $per_km_shipping_charge;
            $dataArray['minimum_shipping_charge'] = $minimum_shipping_charge;
            $dataArray['maximum_shipping_charge'] =  $maximum_shipping_charge;
            $dataArray['max_cod_order_amount_value']=  $max_cod_order_amount_value;
            $dataArray['increased']=$increased;
            $dataArray['distance_data'] =$distance_data;

            $home_delivery = BusinessSetting::where('key', 'home_delivery')->first()?->value ?? null;
            if ($home_delivery == null && $request->order_type == 'delivery') {
                return response()->json(['status'=>'failed','code' => 'order_type', 'message' => translate('messages.Home_delivery_is_disabled')], 403);
            }

            $take_away = BusinessSetting::where('key', 'take_away')->first()?->value ?? null;
            if ($take_away == null && $request->order_type == 'take_away') {
                return response()->json(['status'=>'failed', 'code' => 'order_type', 'message' => translate('messages.Take_away_is_disabled')], 403);
            }

            $settings =  BusinessSetting::where('key', 'cash_on_delivery')->first();
            $cod = json_decode($settings?->value, true);
            if(isset($cod['status']) &&  $cod['status'] != 1 && $request->payment_method == 'cash_on_delivery'){
                return response()->json(['status'=>'failed','code' => 'order_time', 'message' => translate('messages.Cash_on_delivery_is_not_active')], 403);

            }

            if($request->schedule_at && $schedule_at < now())
            {
                return response()->json(['status'=>'failed','code' => 'order_time', 'message' => translate('messages.you_can_not_schedule_a_order_in_past')], 406);
            }

            $result=array();

            $orderResult=array();
            $restaurantIds=$request->restaurant_id;
            for($i=0;$i<count($restaurantIds);$i++){
                $restaurantId=$restaurantIds[$i];

                $result=$this->orderSingleRestaurant($request, $restaurantId, $dataArray);

                if($result['status']=='failed' && count($orderResult)==0){
                     return response()->json(['status'=>'failed',"data"=>$result], 400);
                } if($result['status']=='failed' && count($orderResult)==1){
                     $result['order_result']=$orderResult[0];
                     return response()->json(['status'=>'failed',"data"=>$result], 400);
                } else {
                    $orderResult[]=$result;
                }
            }

            return response()->json([
                'status'=>'success',
                "data"=>$orderResult
            ], 200);

        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getMessage()." at line no: ".$e->getLine()." in ".$e->getFile()
             ], 500);
        }
    }

    public function orderSingleRestaurant($request, $restaurantId, $dataArray)
    {
        $coupon=$dataArray['coupon'];
        $delivery_charge=$dataArray['delivery_charge'];
        $free_delivery_by=$dataArray['free_delivery_by'];
        $coupon_created_by=$dataArray['coupon_created_by'];
        $schedule_at=$dataArray['schedule_at'];
        $per_km_shipping_charge=$dataArray['per_km_shipping_charge'];
        $minimum_shipping_charge=$dataArray['minimum_shipping_charge'];
        $maximum_shipping_charge=$dataArray['maximum_shipping_charge'];
        $max_cod_order_amount_value=$dataArray['max_cod_order_amount_value'];
        $increased=$dataArray['increased'];
        $distance_data=$dataArray['distance_data'];

       
        $restaurant = Restaurant::with(['discount', 'restaurant_sub'])->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = '.$schedule_at->format('w').' and `restaurant_schedule`.`opening_time` < "'.$schedule_at->format('H:i:s').'" and `restaurant_schedule`.`closing_time` >"'.$schedule_at->format('H:i:s').'") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

       // $restaurant = Restaurant::with(['discount', 'restaurant_sub'])->where('id', $restaurantId)->open()->first();

        if(!$restaurant) {
            return  ['status'=>'failed', 'code' => 'order_time', 'message' => translate('messages.restaurant_not_found')];
        }

 

        $rest_sub=$restaurant?->restaurant_sub;
        if ($restaurant->restaurant_model == 'subscription' && isset($rest_sub)) {
            if($rest_sub->max_order != "unlimited" && $rest_sub->max_order <= 0){
                return  ['status'=>'failed', 'code' => 'order-confirmation-error', 'message' => translate('messages.Sorry_the_restaurant_is_unable_to_take_any_order_!')];
            }

        }
        elseif( $restaurant->restaurant_model == 'unsubscribed'){
            return  ['status'=>'failed', 'code' => 'order-confirmation-model', 'message' => translate('messages.Sorry_the_restaurant_is_unable_to_take_any_order_!')];
        }


        if($request->schedule_at && !$restaurant->schedule_order){
            return  ['status'=>'failed', 'code' => 'schedule_at', 'message' => translate('messages.schedule_order_not_available')];
        }


        if($restaurant->open == false && !$request->subscription_order){
          return  ['status'=>'failed', 'code' => 'order_time', 'message' => translate('messages.restaurant_is_closed_at_order_time')];
        }

        $instant_order = BusinessSetting::where('key', 'instant_order')->first()?->value;
        if(($instant_order != 1 || $restaurant->restaurant_config?->instant_order != 1) && !$request->schedule_at && !$request->subscription_order){
           return  ['status'=>'failed', 'code' => 'instant_order', 'message' => translate('messages.instant_order_is_not_available_for_now!')];
        }


        DB::beginTransaction();

        if ($request['coupon_code']) {
            $coupon = Coupon::active()->where(['code' => $request['coupon_code']])->first();
            if (isset($coupon)) {
                if($request->is_guest){
                    $staus = CouponLogic::is_valid_for_guest(coupon: $coupon, restaurant_id: $restaurantId);
                }else{
                    $staus = CouponLogic::is_valide(coupon: $coupon, user_id: $request->user->id ,restaurant_id: $restaurantId);
                }

                $message= match($staus){
                    407 => translate('messages.coupon_expire'),
                    408 => translate('messages.You_are_not_eligible_for_this_coupon'),
                    406 => translate('messages.coupon_usage_limit_over'),
                    404 => translate('messages.not_found'),
                    default => null ,
                };
                if ($message != null) {
                    return  ['status'=>'failed', 'code' => 'coupon', 'message' => $message, "coupon_status"=>$staus];
                }
                $coupon->increment('total_uses');

                $coupon_created_by =$coupon->created_by;
                if($coupon->coupon_type == 'free_delivery'){
                    $delivery_charge = 0;
                    $free_delivery_by =  $coupon_created_by;
                    $coupon_created_by = null;
                    $coupon = null;
                }

            } else {
                return  ['status'=>'failed', 'code' => 'coupon', 'message' => translate('messages.not_found')];
            }
        }


        $data = Helpers::vehicle_extra_charge(distance_data:$distance_data);
        $extra_charges = (float) (isset($data) ? $data['extra_charge']  : 0);
        $vehicle_id= (isset($data) ? (int) $data['vehicle_id']  : null);

        if($request->latitude && $request->longitude){
            $zone = Zone::where('id', $restaurant->zone_id)->whereContains('coordinates', new Point($request->latitude, $request->longitude, POINT_SRID))->first();            
            if(!$zone)
            {
                $errors = [];
                array_push($errors, ['code' => 'coordinates', 'message' => translate('messages.out_of_coverage')]);
                return  [
                    'status'=>'failed',
                    'errors' => $errors
                ];
            }
            if( $zone->per_km_shipping_charge && $zone->minimum_shipping_charge ) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
                $maximum_shipping_charge = $zone->maximum_shipping_charge;
                $max_cod_order_amount_value= $zone->max_cod_order_amount;
                if($zone->increased_delivery_fee_status == 1){
                    $increased=$zone->increased_delivery_fee ?? 0;
                }
            }
        }


        // if(($request['order_type'] != 'take_away' || $request['order_type'] != 'dine_in') && !$restaurant->free_delivery &&  !isset($delivery_charge) && ($restaurant->restaurant_model == 'subscription' && isset($restaurant->restaurant_sub) && $restaurant->restaurant_sub->self_delivery == 1  || $restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1 )){

        if (
            !in_array($request['order_type'], ['take_away', 'dine_in'])
            && !$restaurant->free_delivery
            && !isset($delivery_charge)
            && (
                ($restaurant->restaurant_model == 'subscription'
                    && isset($restaurant->restaurant_sub)
                    && $restaurant->self_delivery_system == 1)
                // ||
                // ($restaurant->restaurant_model == 'commission'
                //     && $restaurant->self_delivery_system == 1)
            )
        ) {
                $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
                $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
                $maximum_shipping_charge = $restaurant->maximum_shipping_charge;
                $extra_charges= 0;
                $vehicle_id=null;
                $increased=0;
        }

        if($restaurant->free_delivery || $free_delivery_by == 'vendor' ){
            $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
            $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            $maximum_shipping_charge = $restaurant->maximum_shipping_charge;
            $extra_charges= 0;
            $vehicle_id=null;
            $increased=0;
        }

        $original_delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge + $extra_charges  : $minimum_shipping_charge + $extra_charges;

        //if($request['order_type'] == 'take_away' || $request['order_type'] == 'dine_in')

        if(in_array($request['order_type'], ['take_away', 'dine_in', 'book_a_table']))
        {
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
            $maximum_shipping_charge = 0;
            $extra_charges= 0;
            $distance_data = 0;
            $vehicle_id=null;
            $increased=0;
            $original_delivery_charge =0;
        }

        if ( $maximum_shipping_charge  > $minimum_shipping_charge  && $original_delivery_charge >  $maximum_shipping_charge ){
            $original_delivery_charge = $maximum_shipping_charge;
        }
        else{
            $original_delivery_charge = $original_delivery_charge;
        }

        if(!isset($delivery_charge)){
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
            if ( $maximum_shipping_charge  > $minimum_shipping_charge  && $delivery_charge + $extra_charges >  $maximum_shipping_charge ){
                $delivery_charge =$maximum_shipping_charge;
            }
            else{
                $delivery_charge =$extra_charges + $delivery_charge;
            }
        }


        if($increased > 0 ){
            if($delivery_charge > 0){
                $increased_fee = ($delivery_charge * $increased) / 100;
                $delivery_charge = $delivery_charge + $increased_fee;
            }
            if($original_delivery_charge > 0){
                $increased_fee = ($original_delivery_charge * $increased) / 100;
                $original_delivery_charge = $original_delivery_charge + $increased_fee;
            }
        }
        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : ($request->user?$request->user->f_name . ' ' . $request->user->l_name:''),
            'contact_person_number' => $request->contact_person_number ? ($request->user ? $request->contact_person_number :str_replace('+', '', $request->contact_person_number)) : ($request->user?$request->user->phone:''),
            'contact_person_email' => $request->contact_person_email ? $request->contact_person_email : ($request->user?$request->user->email:''),
            'address_type' => $request->address_type?$request->address_type:'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string)$request->longitude,
            'latitude' => (string)$request->latitude,
        ];

        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;

        $order_details = [];
        $order = new Order();
        $orderId = time();

        $order_status ='pending';
        if(($request->partial_payment && $request->payment_method != 'offline_payment') || $request->payment_method == 'wallet' ){
            $order_status ='confirmed';
        }

        if(in_array($request['order_type'], ['dine_in', 'book_a_table'])){
            $order_status ='confirmed';
        }

        $order->order_no = $orderId;
        $order->distance = $distance_data;
        $order->user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order->order_amount = $request['order_amount'];
        $order->payment_status = ($request->partial_payment ? 'partially_paid' : ($request['payment_method'] == 'wallet' ? 'paid' : 'unpaid'));
        $order->order_status = $order_status;
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->partial_payment? 'partial_payment' :$request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->order_type = $request['order_type'];


        $order->restaurant_id = $restaurantId;
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit'))??0;
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at?1:0;
        $order->is_guest = $request->user ? 0 : 1;
        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        $order->vehicle_id = $vehicle_id;
        $order->pending = now();

        if ($order_status == 'confirmed') {
            $order->confirmed = now();
        }

        $order->created_at = now();
        $order->updated_at = now();

        $order->cutlery = $request->cutlery ? 1 : 0;
        $order->unavailable_item_note = $request->unavailable_item_note ?? null ;
        $order->delivery_instruction = $request->delivery_instruction ?? null ;
        $order->tax_percentage =0 ;


        $carts = Cart::where('user_id', $order->user_id)->where('restaurant_id', $restaurantId)->where('is_guest',$order->is_guest)
        ->when(isset($request->is_buy_now) && $request->is_buy_now == 1 && $request->cart_id, function ($query) use ($request) {
            return $query->where('id',$request->cart_id);
        })
        ->get()->map(function ($data) {
            $data->add_on_ids = json_decode($data->add_on_ids,true);
            $data->add_on_qtys = json_decode($data->add_on_qtys,true);
            $data->variations = json_decode($data->variations,true);
            return $data;
        });

        if(isset($request->is_buy_now) && $request->is_buy_now == 1){
            $carts = $request['cart'];
        }

        foreach ($carts as $c) {

            if ($c['item_type'] === 'App\Models\ItemCampaign' || $c['item_type'] === 'AppModelsItemCampaign')  {
                $product = ItemCampaign::active()->find($c['item_id']);
                $campaign_id = $c['item_id'];
                $code = 'campaign';
            } else{
                $product = Food::active()->find($c['item_id']);
                $food_id = $c['item_id'];
                $code = 'food';
            }

            if($product->restaurant_id != $restaurantId){
                return  ['status'=>'failed', 'code' => 'restaurant', 'message' => translate('messages.you_need_to_order_food_from_single_restaurant')];
            }

            if ($product) {
                if($product->maximum_cart_quantity && ($c['quantity'] > $product->maximum_cart_quantity)){
                     return  ['status'=>'failed', 'code' => 'quantity', 'message' =>$product?->name ?? $product?->title ?? $code.' '.translate('messages.has_reached_the_maximum_cart_quantity_limit')];
                }


                
                 $selectedAddons=$c->add_on_ids ?? [];

                 $selected_addons=array();
                 $selected_addon_quantity=array();
                 foreach($selectedAddons as $addon){
                    $selected_addons[]=$addon['add_on_id'];
                    $selected_addon_quantity[]=$addon['add_on_qty'];  
                 }

                $addon_data = Helpers::calculate_addon_price(addons: \App\Models\AddOn::whereIn('id',$selected_addons)->get(), add_on_qtys: $selected_addon_quantity);


                $variation_options=array();
                $selectedVariations=$c->variations ?? [];
                foreach($selectedVariations as $variationOption){
                    $variation_options[]=$variationOption['variation_option_id'];

                    $variation_options_qty[$variationOption['variation_option_id']]=$variationOption['variation_qty'];
                }
              
                if($code == 'food'){
                   
                    $addonAndVariationStock= Helpers::addonAndVariationStockCheck(product:$product,quantity: $c['quantity'],add_on_qtys:$selected_addon_quantity, variation_options:$variation_options,add_on_ids:$selectedAddons,incrementCount: true );
                    if(data_get($addonAndVariationStock, 'out_of_stock') != null) {
                        return  ['status'=>'failed', 'code' => data_get($addonAndVariationStock, 'type') ?? 'food', 'message' =>data_get($addonAndVariationStock, 'out_of_stock') ];
                    }
                }

                $product_variations = json_decode($product->variations, true);

 
                    
                $variations=[];
               
                if (count($product_variations)) {
                    $variation_data = Helpers::get_varient($product_variations, $selectedVariations);
                    $price = ($product['price']*$c->quantity) + $variation_data['price'];
                    $variations = $variation_data['variations'];

                } else {
                    $price = $product['price']*$c->quantity;
                }
 

                $product->tax = $restaurant->tax;

                $product =Helpers::product_data_formatting($product, false, false, app()->getLocale());

                $or_d = [
                    'food_id' => $food_id ??  null,
                    'item_campaign_id' => $campaign_id ?? null,
                    'food_details' => json_encode($product),
                    'quantity' => $c['quantity'],
                    'price' => round($price, config('round_up_to_digit')),
                    'tax_amount' =>0,//Helpers::tax_calculate(food:$product, price:$price),
                    'discount_on_food' => Helpers::product_discount_calculate(product:$product, price:$price, restaurant:$restaurant),
                    'discount_type' => 'discount_on_product',
                    'variation' =>json_encode($variations),
                    'add_ons' => json_encode($addon_data['addons']),
                    'total_add_on_price' => $addon_data['total_add_on_price'],
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $order_details[] = $or_d;
                $total_addon_price += $or_d['total_add_on_price'];
                $product_price += $price;
                $restaurant_discount_amount += $or_d['discount_on_food']*$or_d['quantity'];

            } else {
               return  ['status'=>'failed','code' => $code ?? null, 'message' => translate('messages.product_unavailable_warning')];
            }
        }


        $order->discount_on_product_by = 'vendor';

        $restaurant_discount = Helpers::get_restaurant_discount(restaurant:$restaurant);
        if(isset($restaurant_discount)){
            $order->discount_on_product_by = 'admin';

            if($product_price + $total_addon_price < $restaurant_discount['min_purchase']){
                $restaurant_discount_amount = 0;
            }

            if($restaurant_discount_amount > $restaurant_discount['max_discount']){
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }

        $coupon_discount_amount = $coupon ? CouponLogic::get_discount(coupon:$coupon, order_amount: $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount ;

        if($order->is_guest  == 0 && $order->user_id  && !($request->subscription_order && $request->subscription_quantity) ){
            $user= User::withcount('orders')->find($order->user_id);
            $discount_data= Helpers::getCusromerFirstOrderDiscount(order_count:$user->orders_count ,user_creation_date:$user->created_at,  refby:$user->ref_by, price: $total_price);
                if(data_get($discount_data,'is_valid') == true &&  data_get($discount_data,'calculated_amount') > 0){
                    $total_price = $total_price - data_get($discount_data,'calculated_amount');
                    $order->ref_bonus_amount = data_get($discount_data,'calculated_amount');
                }
        }

        $tax = ($restaurant->tax > 0)?$restaurant->tax:0;
        $order->tax_status = 'excluded';

        $tax_included =BusinessSetting::where(['key'=>'tax_included'])->first() ?  BusinessSetting::where(['key'=>'tax_included'])->first()->value : 0;
        if ($tax_included ==  1){
            $order->tax_status = 'included';
        }

        $total_tax_amount=0;//Helpers::product_tax(price:$total_price, tax:$tax, is_include:$order->tax_status =='included');

        $tax_a=0;//$order->tax_status =='included'?0:$total_tax_amount;

        if($restaurant->minimum_order > $product_price + $total_addon_price )
        {
            return  ['status'=>'failed', 'code' => 'order_amount', 'message' => translate('messages.you_need_to_order_at_least').' '. $restaurant->minimum_order.' '.Helpers::currency_code()];
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if(isset($free_delivery_over))
        {
            if($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount)
            {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        $free_delivery_distance = BusinessSetting::where('key', 'free_delivery_distance')->first()->value;
        if($restaurant->self_delivery_system == 0 && isset($free_delivery_distance))
        {
            if($request->distance <= $free_delivery_distance)
            {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if($restaurant->free_delivery){
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if($restaurant->self_delivery_system == 1 && $restaurant->free_delivery_distance_status == 1 && $restaurant->free_delivery_distance_value && ($request->distance <= $restaurant->free_delivery_distance_value)){
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        $order->coupon_created_by = $coupon_created_by;
        //Added service charge
        $additional_charge_status = BusinessSetting::where('key', 'additional_charge_status')->first()?->value;
        $additional_charge = BusinessSetting::where('key', 'additional_charge')->first()?->value;
        if ($additional_charge_status == 1) {
            $order->additional_charge = $additional_charge ?? 0;
        } else {
            $order->additional_charge = 0;
        }

        //Extra packaging charge
        $extra_packaging_data = BusinessSetting::where('key', 'extra_packaging_charge')->first()?->value ?? 0;
        $order->extra_packaging_amount =  ($extra_packaging_data == 1 && $restaurant?->restaurant_config?->is_extra_packaging_active == 1  && $request?->extra_packaging_amount > 0)?$restaurant?->restaurant_config?->extra_packaging_amount:0;

        $order_amount = round($total_price + $tax_a + $order->delivery_charge + $order->additional_charge + $order->extra_packaging_amount, config('round_up_to_digit'));
        if($request->payment_method == 'wallet' && $request->user->wallet_balance < $order_amount)
        {
            return  ['status'=>'failed','code' => 'order_amount', 'message' => translate('messages.insufficient_balance')];
        }
        if ($request->partial_payment && $request->user->wallet_balance > $order->order_amount) {
             return  ['status'=>'failed','code' => 'partial_payment', 'message' => translate('messages.order_amount_must_be_greater_than_wallet_amount')];
        }
        try {
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount= round($restaurant_discount_amount, config('round_up_to_digit'));
            $order->total_tax_amount= round($total_tax_amount, config('round_up_to_digit'));
            $order->order_amount = $order_amount + $order->dm_tips;

            $total=$order_amount + $order->dm_tips;

            $paymentSettings=PaymentSetting::where('id', 1)->first();

            $processing_fee = $total * ($paymentSettings->platform_fee)/100; 

            $order->processing_charges = round($processing_fee,2);

            $order->order_amount = round($order_amount + $order->dm_tips+$processing_fee,2);


            if( $max_cod_order_amount_value > 0 && $order->payment_method == 'cash_on_delivery' && $order->order_amount > $max_cod_order_amount_value){
                return  ['status'=>'failed','code' => 'order_amount', 'message' => translate('messages.You can not Order more then ').$max_cod_order_amount_value .Helpers::currency_symbol().' '. translate('messages.on COD order.')];
            }

            // DB::beginTransaction();

            // new Order Subscription create
            if($request->subscription_order && $request->subscription_quantity){
                $subscription = new Subscription();
                $subscription->status = 'active';
                $subscription->start_at = $request->subscription_start_at;
                $subscription->end_at = $request->subscription_end_at;
                $subscription->type = $request->subscription_type;
                $subscription->quantity = $request->subscription_quantity;
                $subscription->user_id = $request->user->id;
                $subscription->restaurant_id = $restaurant->id;
                $subscription->save();
                $order->subscription_id = $subscription->id;
                // $subscription_schedules =  Helpers::get_subscription_schedules($request->subscription_type, $request->subscription_start_at, $request->subscription_end_at, json_decode($request->days, true));

                $days = array_map(function($day)use($subscription){
                    $day['subscription_id'] = $subscription->id;
                    $day['type'] = $subscription->type;
                    $day['created_at'] = now();
                    $day['updated_at'] = now();
                    return $day;
                },json_decode($request->subscription_days, true));
                // info(['SubscriptionSchedule_____', $days]);
                SubscriptionSchedule::insert($days);
                // $order->checked = 1;
            }

            $order->save();
            // new Order Subscription logs create for the order
            OrderLogic::create_subscription_log(id:$order->id);
            // End Order Subscription.

            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;

                if($restaurant_discount_amount <= 0 ){
                    $order_details[$key]['discount_on_food'] = 0;
                }
            }


            $res=OrderDetail::insert($order_details);
            if(!isset($request->is_buy_now) || (isset($request->is_buy_now) && $request->is_buy_now == 0 )){
                foreach ($carts as $cart) {
                    $cart->delete();
                }
            }

            $restaurant->increment('total_order');

            if($request->user){
                $customer = $request->user;
                $customer->zone_id = $restaurant->zone_id;
                $customer->save();

            Helpers::visitor_log(model: 'restaurant', user_id:$customer->id, visitor_log_id:$restaurant->id, order_count:true);
            }
            if($request->payment_method == 'wallet') CustomerLogic::create_wallet_transaction(user_id:$order->user_id, amount:$order->order_amount, transaction_type:'order_place', referance:$order->id);

            if ($request->partial_payment) {
                if ($request->user->wallet_balance<=0) {
                   return  ['status'=>'failed','code' => 'order_amount', 'message' => translate('messages.insufficient_balance_for_partial_amount')];
                }
                $p_amount = min($request->user->wallet_balance, $order->order_amount);
                $unpaid_amount = $order->order_amount - $p_amount;
                $order->partially_paid_amount = $p_amount;
                $order->save();
                CustomerLogic::create_wallet_transaction($order->user_id, $p_amount, 'partial_payment', $order->id);
                OrderLogic::create_order_payment(order_id:$order->id, amount:$p_amount, payment_status:'paid', payment_method:'wallet');
                OrderLogic::create_order_payment(order_id:$order->id, amount:$unpaid_amount, payment_status:'unpaid',payment_method:$request->payment_method);
            }


            if($order->is_guest  == 0 && $order->user_id && !($request->subscription_order && $request->subscription_quantity) ){
                $this->createCashBackHistory($order->order_amount, $order->user_id,$order->id);
            }

            DB::commit();
            
            //PlaceOrderMail Notification
            
            $paymentSettings=PaymentSetting::where('id', 1)->first();


 
            return [
                'status'=>'success', 
                'order_id' => $order->id,
                'total_ammount' => $total_price+$order->delivery_charge+$tax_a
            ];

        } catch (\Exception $e) {
            return ['status'=>'failed', 'message'=>$e->getMessage()];
        }

         return ['status'=>'failed', 'code' => 'order_time', 'message' => translate('messages.failed_to_place_order')];
    }


    

    private function createCashBackHistory($order_amount, $user_id,$order_id){
        $cashBack =  Helpers::getCalculatedCashBackAmount(amount:$order_amount, customer_id:$user_id);
        if(data_get($cashBack,'calculated_amount') > 0){
            $CashBackHistory = new CashBackHistory();
            $CashBackHistory->user_id = $user_id;
            $CashBackHistory->order_id = $order_id;
            $CashBackHistory->calculated_amount = data_get($cashBack,'calculated_amount');
            $CashBackHistory->cashback_amount = data_get($cashBack,'cashback_amount');
            $CashBackHistory->cash_back_id = data_get($cashBack,'id');
            $CashBackHistory->cashback_type = data_get($cashBack,'cashback_type');
            $CashBackHistory->min_purchase = data_get($cashBack,'min_purchase');
            $CashBackHistory->max_discount = data_get($cashBack,'max_discount');
            $CashBackHistory->save();

            $CashBackHistory?->order()->update([
                'cash_back_id'=> $CashBackHistory->id
            ]);
        }
        return true;
    }
     

    /**
     * track orders list for customer after order place
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function track_order(Request $request)
    {
        try{ 
            $validator = Validator::make($request->all(), [
                'order_id' => 'required',
                'guest_id' => $request->user ? 'nullable' : 'required',
                'contact_number' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([ 'status' => 'failed','errors' => Helpers::error_processor($validator)], 403);
            }
            $user_id = $request->user ? $request->user->id : $request['guest_id'];

            $order = Order::with(['restaurant','restaurant.restaurant_sub', 'refund', 'delivery_man', 'delivery_man.rating','subscription','payments', 'details'])->withCount('details')->where(['id' => $request['order_id'], 'user_id' => $user_id])
            ->when(!$request->user, function ($query) use ($request) {
                return $query->whereJsonContains('delivery_address->contact_person_number', $request['contact_number']);
            })
            ->Notpos()->first();

            if($order){

                $restaurantArray=array(
                    "id"=>$order['restaurant']->id,
                    "name"=>$order['restaurant']->name,
                    "longitude"=>$order['restaurant']->longitude,
                    "latitude"=>$order['restaurant']->latitude,
                    "address"=>$order['restaurant']->address,
                    "city"=>$order['restaurant']->city,
                    "state"=>$order['restaurant']->stateInfo?->name,
                    "zipcode"=>$order['restaurant']->zipcode,
                    "logo"=>$order['restaurant']->logo,
                );
                unset($order['restaurant']);
                $order['restaurant'] = $restaurantArray;
                $order['delivery_address'] = $order['delivery_address']?json_decode($order['delivery_address'],true):$order['delivery_address'];
                $order['delivery_man'] = $order['delivery_man']?Helpers::deliverymen_data_formatting([$order['delivery_man']]):$order['delivery_man'];
                $order['offline_payment'] =  isset($order->offline_payments) ? Helpers::offline_payment_formater($order->offline_payments) : null;
                $order['is_reviewed'] =   $order->details_count >  Review::whereOrderId($request->order_id)->count() ? False :True ;
                $order['is_dm_reviewed'] =  $order?->delivery_man ? DMReview::whereOrderId($order->id)->exists()  : True ;

                if($order->subscription){
                    $order->subscription['delivered_count']= (int) $order->subscription->logs()->whereOrderStatus('delivered')->count();
                    $order->subscription['canceled_count']= (int) $order->subscription->logs()->whereOrderStatus('canceled')->count();
                }

                 
            } else{
                return response()->json([
                   'status' => 'failed',
                   'code' => 'order_not_found', 
                   'message' => translate('messages.Order_not_found')
                ], 404);
            }
            return response()->json([
               'status' => 'success',
               'data' => $order
            ], 200);

        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }

    /**
     * customer or guest user orders list
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
     public function get_customer_order_list(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'limit' => 'required',
                'offset' => 'required',
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            $user_id = $request->user ? $request->user->id : $request['guest_id'];
     
            $paginator = Order::with(['restaurant', 'delivery_man.rating'])->withCount('details')->where(['user_id' => $user_id])->
            whereIn('order_status', ['accepted','pending','confirmed', 'processing', 'handover','picked_up','canceled','failed', 'delivered'])->Notpos()
            ->whereNull('subscription_id')
            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })

            ->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
            $orders = array_map(function ($data) {
                $data['delivery_address'] = $data['delivery_address']?json_decode($data['delivery_address']):$data['delivery_address'];

                 $restaurantArray=array(
                        "id"=>$data['restaurant']->id,
                        "name"=>$data['restaurant']->name,
                        "longitude"=>$data['restaurant']->longitude,
                        "latitude"=>$data['restaurant']->latitude,
                        "address"=>$data['restaurant']->address,
                        "city"=>$data['restaurant']->city,
                        "state"=>$data['restaurant']->stateInfo?->name,
                        "zipcode"=>$data['restaurant']->zipcode,
                        "logo"=>$data['restaurant']->logo,
                    );
                    unset($data['restaurant']);
                    $data['restaurant'] = $restaurantArray;

                $data['restaurant'] = $data['restaurant'];
                $data['delivery_man'] = $data['delivery_man']?Helpers::deliverymen_data_formatting([$data['delivery_man']]):$data['delivery_man'];
                $data['is_reviewed'] =   $data['details_count'] >  Review::whereOrderId($data->id)->count() ? False :True ;
                $data['is_dm_reviewed'] = $data['delivery_man'] ? DMReview::whereOrderId($data->id)->exists()  : True ;
                return $data;
            }, $paginator->items());
            $data = [
                'total_size' => $paginator->total(),
                'limit' => $request['limit'],
                'offset' => $request['offset'],
                'orders' => $orders
            ];
            return response()->json(['status'=>'success', 'data'=>$data], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }

    /**
     * customer or guest user detailed orders information
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
    public function get_order_details(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_id' => 'required',
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $order = Order::with('details','offline_payments','subscription.schedules')->where('user_id', $user_id)

            ->when(!isset($request->user) , function($query){
                $query->where('is_guest' , 1);
            })

            ->when(isset($request->user)  , function($query){
                $query->where('is_guest' , 0);
            })
            ->where('id',$request->order_id)->first();
            
            $details = $order?->details;

            if ($details != null && $details->count() > 0) {
                $storage = [];
                foreach ($details as $item) {
                    $item['add_ons'] = json_decode($item['add_ons']);
                    $item['variation'] = json_decode($item['variation']);
                    $item['food_details'] = json_decode($item['food_details'], true);
                    $item['zone_id'] = (int) (isset($order->zone_id) ? $order->zone_id :  $order->restaurant->zone_id);
                    array_push($storage, $item);
                }
                $data = $storage;
                $subscription_schedules =  $order?->subscription?->schedules;
                $offline_payment = isset($order->offline_payments) ? Helpers::offline_payment_formater($order->offline_payments) : null;

                return response()->json(['status'=>'success', 'data'=>['order'=>$order, 'subscription_schedules'=> $subscription_schedules, 'offline_payment' => $offline_payment]
                ], 200);
            }
            else {
                return response()->json(['status'=>'failed','code' => 'order', 'message' => translate('messages.not_found')
                ], 200);
            }
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }

    /**
     * get orders list based on status for admin portal
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */

     public function getAllCustomersOrders( Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'order_status' => 'required|in:all,scheduled,pending,accepted,processing,food_on_the_way,delivered,canceled, failed,refunded,dine_in',
                'pageno' => 'required|min:0',
                'pagelength' => 'required|min:1' 
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }

            $key = explode(' ', $request['search']);
            $status=$request->order_status;
            Order::where(['checked' => 0])->update(['checked' => 1]);

            $orders = Order::with([
                    'customer:id,f_name,l_name,phone,email', 
                    'restaurant' => function ($q) {
                        $q->setEagerLoads([]); // removes default eager loads like restaurant_config
                        $q->select('id', 'name'); // ONLY include these columns
                    }     
                ])
                ->when(isset($request->zone), function ($query) use ($request) {
                    return $query->whereHas('restaurant', function ($q) use ($request) {
                        return $q->whereIn('zone_id', $request->zone);
                    });
                })
                ->when($status == 'scheduled', function ($query) {
                    return $query->whereRaw('created_at <> schedule_at');
                })
                ->when($status == 'searching_for_deliverymen', function ($query) {
                    return $query->SearchingForDeliveryman();
                })
                ->when($status == 'pending', function ($query) {
                    return $query->Pending();
                })
                ->when($status == 'accepted', function ($query) {
                    return $query->AccepteByDeliveryman();
                })
                ->when($status == 'processing', function ($query) {
                    return $query->Preparing();
                })
                ->when($status == 'food_on_the_way', function ($query) {
                    return $query->FoodOnTheWay();
                })
                ->when($status == 'delivered', function ($query) {
                    return $query->Delivered();
                })
                ->when($status == 'canceled', function ($query) {
                    return $query->Canceled();
                })
                ->when($status == 'failed', function ($query) {
                    return $query->failed();
                })
                ->when($status == 'requested', function ($query) {
                    return $query->Refund_requested();
                })
                ->when($status == 'rejected', function ($query) {
                    return $query->Refund_request_canceled();
                })
                ->when($status == 'refunded', function ($query) {
                    return $query->Refunded();
                })
                ->when($status == 'scheduled', function ($query) {
                    return $query->Scheduled();
                })
                ->when($status == 'on_going', function ($query) {
                    return $query->Ongoing();
                })
                ->when($status == 'dine_in', function ($query) {
                    return $query->where('order_type','dine_in');
                })
                ->when( !in_array($status,['all','scheduled','canceled','refund_requested','refunded','delivered','failed','dine_in'])  , function ($query) {
                    return $query->OrderScheduledIn(30);
                })
                ->when(isset($request->vendor) && count($request->vendor) > 0, function ($query) use ($request) {
                    $query->whereHas('restaurant', function ($q) use ($request) {
                        $q->whereIn('id', $request->vendor);
                    });
                })
                ->when(isset($request->orderStatus) && $status == 'all', function ($query) use ($request) {
                    return $query->whereIn('order_status', $request->orderStatus);
                })
                ->when(isset($request->scheduled) && $status == 'all', function ($query) {
                    return $query->scheduled();
                })
                ->when(isset($request->order_type), function ($query) use ($request) {
                    return $query->where('order_type', $request->order_type);
                })
                ->when($request?->from_date != null && $request?->to_date != null, function ($query) use ($request) {
                    return $query->whereBetween('created_at', [$request->from_date . " 00:00:00", $request->to_date . " 23:59:59"]);
                })
                ->when(isset($key), function ($query) use ($key) {
                    return $query->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('id', 'like', "%{$value}%")
                                ->orWhere('order_status', 'like', "%{$value}%")
                                ->orWhere('transaction_reference', 'like', "%{$value}%");
                        }
                    });
                })
                ->Notpos()
                ->hasSubscriptionToday()
                ->orderBy('schedule_at', 'desc');

            $pageno = 0; $pagelength = 0; 
            $totalrecords = $orders->count();
            if (isset($request->pagelength, $request->pageno) && !empty($request->pagelength)) {
                $pagelength = $request->pagelength;
                $pageno = $request->pageno;
            }    
            $orders = $orders->latest()
             ->skip(($pageno - 1) * $pagelength)
             ->take($pagelength)
             ->get();
              

            $data['data'] = $orders;
            $data['current_page'] =$pageno ? $pageno : '1';
            $data['total'] = $totalrecords;
            $data['per_page'] = $pagelength ? $pagelength : '10';
            $data['orderstatus'] = $request?->orderStatus ?? [];
            $data['scheduled'] =  $request?->scheduled ?? 0;
            $data['vendor_ids'] =  $request?->vendor ?? [];
            $data['from_date'] =  $request?->from_date ?? null;
            $data['to_date'] = $request?->to_date ?? null;
            $data['order_type'] =  $request?->order_type ?? null;
           
            return response()->json(['status' => 'success', 'data' => $data], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }


    public function getOrderDetails(Request $request, $id)
    {
        try{
           
        $order = Order::with(['restaurant.stateInfo', 'offline_payments','payments','subscription','subscription.schedule_today','details', 'refund','restaurant' => function ($query) {
                   
                return $query->setEagerLoads([])->select('id', 'name','address','zipcode', 'state', 'city', 'longitude', 'latitude')->with('stateInfo')->withCount('orders');
        }, 'customer' => function ($query) {
            return $query->withCount('orders');
        }, 'delivery_man' => function ($query) {
            return $query->withCount('orders');
        }, 'details.food' => function ($query) {
            return $query->withoutGlobalScope(RestaurantScope::class);
        }, 'details.campaign' => function ($query) {
            return $query->withoutGlobalScope(RestaurantScope::class);
        }])->where(['id' => $id])->Notpos()->first();

        if(is_null($order)){
             return response()->json([
               'status' => 'failed',
               'message' => "Order details not food "
             ], 400);
        }
        
          if (($order?->restaurant?->self_delivery_system && $order?->restaurant?->restaurant_model == 'commission') ||
            ($order?->restaurant?->restaurant_model == 'subscription' &&   $order?->restaurant?->restaurant_sub?->self_delivery == 1)  ) {
                $deliveryMen = DeliveryMan::with('last_location')->where('restaurant_id', $order->restaurant_id)->available()->active()->get();

            } else {
                if($order->restaurant !== null){
                    $deliveryMen = DeliveryMan::with('last_location')->where('zone_id', $order->restaurant->zone_id)->where(function($query)use($order){
                            $query->where('vehicle_id',$order->vehicle_id)->orWhereNull('vehicle_id');
                    })
                    ->available()->active()->get();
                } else{
                    $deliveryMen = DeliveryMan::with(['last_location', 'wallet'])->where('zone_id', '=', NULL)->where('vehicle_id',$order->vehicle_id)->active()->get();
                }
            }

            $category = $request->query('category_id', 0);
            // $sub_category = $request->query('sub_category', 0);
            $categories = Category::active()->get();
            $keyword = $request->query('keyword', false);
            $key = explode(' ', $keyword);
            $products = Food::withoutGlobalScope(RestaurantScope::class)->where('restaurant_id', $order->restaurant_id)
                ->when($category, function ($query) use ($category) {
                    $query->whereHas('category', function ($q) use ($category) {
                        return $q->whereId($category)->orWhere('parent_id', $category);
                    });
                })
                ->when($keyword, function ($query) use ($key) {
                    return $query->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('name', 'like', "%{$value}%");
                        }
                    });
                })
                ->latest()->paginate(10);
            $editing = false;
            
            $deliveryMen = Helpers::deliverymen_list_formatting(data:$deliveryMen, restaurant_lat: $order?->restaurant?->latitude, restaurant_lng: $order?->restaurant?->longitude);


            $selected_delivery_man = DeliveryMan::with('last_location')->where('id',$order->delivery_man_id)->first() ?? [];
            if($order->delivery_man){
                $selected_delivery_man = Helpers::deliverymen_list_formatting(data:$selected_delivery_man, restaurant_lat: $order?->restaurant?->latitude, restaurant_lng: $order?->restaurant?->longitude , single_data:true);
            }

            $data['order']=$order;
            $data['deliveryMen']=$deliveryMen;
            $data['categories']=$categories;
            $data['products']=$products;
            $data['category']=$category;
            $data['keyword']=$keyword;
            $data['editing']=$editing;
            $data['selected_delivery_man']=$selected_delivery_man;
              return response()->json([
               'status' => 'success',
               'data' => $data
            ], 200);
        
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }


    public function downloadPrinterInvoice($orderId)
    {
        try{
           
            $order = Order::with('restaurant', 'restaurant.stateInfo')->where('id', $orderId)->first();

            if (is_null($order)) {
                 return response()->json([
                   'status' => 'failed',
                   'message' => "No order details found" 
                 ], 400); 
            }

            $orderDetailsResults = OrderDetail::with('food')->where('order_id', $order->id)->get();

            $logoPath = public_path('assets/img/Logo.png'); // Correct absolute server path

            $logoBase64 = base64_encode(file_get_contents($logoPath));
             
            $pdf = Pdf::loadView('pdf.printer_invoice', compact('order', 'logoBase64', 'orderDetailsResults'))
              ->setOptions([
                  'defaultFont' => 'sans-serif',
                  'isRemoteEnabled' => true // still ok if you want remote images
            ]);

           return $pdf->stream('printer_invoice-' . rand(00001, 99999) . '.pdf');
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }

     public function downloadOrderInvoice($orderId)
    {
        try{
           
            $order = Order::with('restaurant', 'restaurant.stateInfo')->where('id', $orderId)->first();
            if (is_null($order)) {
                 return response()->json([
                   'status' => 'failed',
                   'message' => "No order details found" 
                 ], 400); 
            }

            $orderDetailsResults = OrderDetail::where('order_id', $order->id)->get();

            $logoPath = public_path('assets/img/Logo.png'); // Correct absolute server path

            $logoBase64 = base64_encode(file_get_contents($logoPath));
             
            $pdf = Pdf::loadView('pdf.order_invoice', compact('order', 'logoBase64', 'orderDetailsResults'))
              ->setOptions([
                  'defaultFont' => 'sans-serif',
                  'isRemoteEnabled' => true // still ok if you want remote images
            ]);

           return $pdf->download('order_invoice-' . rand(00001, 99999) . '.pdf');
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
    }
}
