<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Cart;
use App\Models\Food;
use App\Models\Item;
use App\Models\AddOn;
use App\Models\ItemCampaign;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\VariationOption;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\PaymentSetting;
use App\Models\Zone;
use App\Models\Coupon;
use App\Models\Restaurant;

use MatanYadaev\EloquentSpatial\Objects\Point;
class CartController extends Controller
{
    public function get_carts(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'guest_id' => $request->user ? 'nullable' : 'required',
                'latitude'=>'required',
                'longitude'=>'required',
                'coupon_code' => 'nullable|string',
                'order_type' => 'required|in:take_away,dine_in,delivery',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed','errors' => Helpers::error_processor($validator)], 403);
            }
            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $is_guest = $request->user ? 0 : 1;

            $orderType='';
            $restaurantIds=array();
            $carts = Cart::where('user_id', $user_id)->where('is_guest',$is_guest)->get()
            ->map(function ($data) use (&$restaurantIds) {

                if (!in_array($data->restaurant_id, $restaurantIds)) {
                    $restaurantIds[] = $data->restaurant_id;
                }

                $data->add_on_ids = json_decode($data->add_on_ids,true) ?? [];
                $data->add_on_qtys = json_decode($data->add_on_qtys,true) ?? [];
                $data->variations = json_decode($data->variations,true) ?? [];
                $data->variation_options = json_decode($data->variation_options,true) ?? [];
                $data->item = Helpers::cart_product_data_formatting($data->item, $data->variations,  $data->variation_options,$data->add_on_ids,
                $data->add_on_qtys, false, app()->getLocale());
                return $data;
            });
            $paymentSettings=PaymentSetting::where('id', 1)->first();

            $othercharges=$this->getCharages($restaurantIds, $request);


            return response()->json(['status'=>'success','data'=>$carts, 'other_charges'=>$othercharges, 'payment_settings'=>$paymentSettings], 200);
        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }


    function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // KM

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return round($earthRadius * $c, 2);
    }


   public function add_to_cart(Request $request)
    {
        try{
             
            $validator = Validator::make($request->all(), [

                // Order info
                'order_type' => 'required|in:take_away,dine_in,delivery',

                // Guest / user
                'guest_id' => $request->user ? 'nullable' : 'required',

                // Item
                'item_id' => [
                    'required',
                    Rule::exists('food', 'id')->whereNull('deleted_at'),
                ],

                'model' => 'required|string|in:Food,ItemCampaign',

                'price' => 'required|numeric|min:0',

                'quantity' => 'required|integer|min:1',

                // Addons
                'addons' => 'nullable|array',
                'addons.*.add_on_id'  => 'required|exists:add_ons,id',
                'addons.*.add_on_qty' => 'required|integer|min:1',

                // Variations
                'variations' => 'nullable|array',
                'variations.*.variation_id'        => 'required|exists:variations,id',
                'variations.*.variation_option_id' => 'required|exists:variation_options,id',
                'variations.*.variation_qty'       => 'required|integer|min:1',

                // Restaurant
                'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at'),
                ],
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed','errors' => Helpers::error_processor($validator)], 403);
            }

            $foodData=Food::where('id', $request->item_id)->where('restaurant_id', $request->restaurant_id)->first();

            if(is_null($foodData)){
                return response()->json(['status'=>'failed','message'=>"Food details not found for selected restaurant"], 400);  
            }

            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $is_guest = $request->user ? 0 : 1;
            $model = $request->model;


            if ($model == 'Food') {
                $model = \App\Models\Food::class;
            } elseif ($model == 'ItemCampaign') {
                $model = \App\Models\ItemCampaign::class;
            }

            $item = $request->model === 'Food' ? Food::find($request->item_id) : ItemCampaign::find($request->item_id);

            if($request->order_type=='dine_in' || $request->order_type=='book_a_table'){
                $cart = Cart::select('restaurant_id')->where('item_type',$model)->where('user_id', $user_id)->where('is_guest',$is_guest)->first();
                if(!empty($cart) && $cart->restaurant_id!=$request->restaurant_id){
                    return response()->json(['status'=>'failed','message'=>"Not allowed to book multiple restaurants for dine in or book a table"], 400);
                }
            }

            $cartDetails = Cart::where('user_id', $user_id)->where('is_guest',$is_guest)->get();
            if ($cartDetails->count() > 0) {

                $firstRecord = $cartDetails->first();

                if($firstRecord->order_type!=$request->order_type){
                    return response()->json(['status'=>'failed','message'=>"Not allowed to change order type and your previously added type is ".str_replace('_', ' ', $firstRecord->order_type)], 400);
                }
            }

            $cart = Cart::where('item_id',$request->item_id)->where('item_type',$model)->where('user_id', $user_id)->where('is_guest',$is_guest)->first();
            if($cart){
                if($request->quantity>0){

                    $addOnsArray = collect($request->addons ?? [])
                        ->map(function ($addon) {
                            return [
                                'add_on_id'  => (int) $addon['add_on_id'],
                                'add_on_qty' => (int) $addon['add_on_qty'],
                            ];
                        })
                        ->values()
                        ->toArray();
 
                    $variationsArray = collect($request->variations ?? [])
                    ->map(function ($variation) {
                        return [
                            'variation_id'        => (int) $variation['variation_id'],
                            'variation_option_id' => (int) $variation['variation_option_id'],
                            'variation_qty' => (int) $variation['variation_qty'],
                        ];
                    })
                    ->values()
                    ->toArray();
                    $cart->item_type = $model;
                    $cart->price = $request->price;
                    $cart->quantity = $request->quantity;
                    $cart->add_on_ids = json_encode($addOnsArray);
                    $cart->variations = json_encode($variationsArray);
                   $cart->save();
                } else if($request->quantity==0){
                    $cart->delete();
                }
            }

            $addOnsArray = collect($request->addons ?? [])
                ->map(function ($addon) {
                    return [
                        'add_on_id'  => (int) $addon['add_on_id'],
                        'add_on_qty' => (int) $addon['add_on_qty'],
                    ];
                })
                ->values()
                ->toArray();

            $variationsArray = collect($request->variations ?? [])
            ->map(function ($variation) {
                return [
                    'variation_id'        => (int) $variation['variation_id'],
                    'variation_option_id' => (int) $variation['variation_option_id'],
                    'variation_qty' => (int) $variation['variation_qty'],
                ];
            })
            ->values()
            ->toArray();

            if($item?->maximum_cart_quantity && ($request->quantity>$item->maximum_cart_quantity)){
                return response()->json(['status'=>'failed','code' => 'cart_item_limit', 'message' => translate('messages.maximum_cart_quantity_exceeded')], 403);
            }
            if($request->model === 'Food'){

                $variation_options=array();
               
                foreach($variationsArray as $variation_option){
                    $variation_options[]=$variation_option['variation_option_id'];
                }

 
                $addonAndVariationStock= Helpers::addonAndVariationStockCheck(product:$item,quantity: $request->quantity,add_on_qtys:[], variation_options: $variation_options,add_on_ids:$request->addons );

                if(data_get($addonAndVariationStock, 'out_of_stock') != null) {
                    return response()->json(['status'=>'failed','code' => 'stock_out', 'message' => data_get($addonAndVariationStock, 'out_of_stock') ], 403);
                }
            }

           
            if(is_null($cart)){
                $cart = new Cart();
                $cart->order_type =$request->order_type;
                $cart->user_id = $user_id;
                $cart->item_id = $request->item_id;
                $cart->restaurant_id = $request->restaurant_id;
                $cart->is_guest = $is_guest;
                $cart->add_on_ids = json_encode($addOnsArray);
                $cart->variations = json_encode($variationsArray);
                $cart->item_type = $model;
                $cart->price = $request->price;
                $cart->quantity = $request->quantity; 
                $cart->save();
                $item->carts()->save($cart);
            }
            return response()->json(['status'=>'success','data'=>null, 'message'=>'Item added successfully'], 200);
       } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }

    public function update_cart(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'cart_id' => 'required',
                'guest_id' => $request->user ? 'nullable' : 'required',
                'price' => 'required|numeric',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }


            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $is_guest = $request->user ? 0 : 1;
            $cart = Cart::where('id', $request->cart_id)->first();
      
            if(is_null($cart)){
                return response()->json(['status'=>'failed','message'=>"Cart details not found"], 400);  
            }

            $item = $cart->item_type === 'App\Models\Food' ? Food::find($cart->item_id) : ItemCampaign::find($cart->item_id);
            if($item->maximum_cart_quantity && ($request->quantity>$item->maximum_cart_quantity)){
                return response()->json(['status'=>'failed', 'code' => 'cart_item_limit', 'message' => translate('messages.maximum_cart_quantity_exceeded')], 403);
            }

            if( $cart->item_type === 'App\Models\Food'){
                $addonAndVariationStock= Helpers::addonAndVariationStockCheck( product:$item, quantity: $request->quantity,add_on_qtys:$request->add_on_qtys, variation_options: $request?->variation_options,add_on_ids:$request->add_on_ids );

                if(data_get($addonAndVariationStock, 'out_of_stock') != null) {
                    return response()->json(['status'=>'failed','code' => 'stock_out', 'message' => data_get($addonAndVariationStock, 'out_of_stock') ], 403);
                }
            }


            $cart->user_id = $user_id;
            $cart->is_guest = $is_guest;
            $cart->add_on_ids = $request->add_on_ids?json_encode($request->add_on_ids):$cart->add_on_ids;
            $cart->add_on_qtys = $request->add_on_qtys?json_encode($request->add_on_qtys):$cart->add_on_qtys;
            $cart->price = $request->price;
            $cart->quantity = $request->quantity;
            $cart->variations = $request->variations?json_encode($request->variations):$cart->variations;
            $cart->variation_options = json_encode($request?->variation_options ?? []);
            $cart->save();

            $carts = Cart::where('user_id', $user_id)->where('is_guest',$is_guest)->get()
            ->map(function ($data) {
                $data->restaurant_name =$data->restaurant?->name;
                $data->add_on_ids = json_decode($data->add_on_ids,true) ?? [];
                $data->add_on_qtys = json_decode($data->add_on_qtys,true) ?? [];
                $data->variations = json_decode($data->variations,true) ?? [];
                $data->variation_options = json_decode($data->variation_options,true) ?? [];
                $data->item = Helpers::cart_product_data_formatting($data->item, $data->variations, $data->variation_options, $data->add_on_ids,
                $data->add_on_qtys, false, app()->getLocale());
                unset($data->restaurant);
                return $data;
            });
            return response()->json(['status'=>'success','data'=>$carts], 200);

        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }

    public function remove_cart_item(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'cart_id' => 'required',
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed','errors' => Helpers::error_processor($validator)], 403);
            }

            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $is_guest = $request->user ? 0 : 1;

            $cart = Cart::where('id', $request->cart_id)->first();
            if(is_null($cart)){
                return response()->json(['status'=>'failed','message'=>"Cart details not found"], 400);  
            }

            $cart->delete();
            return response()->json(['status'=>'success','data'=>null, 'message'=>'Item deleted successfully'], 200);

        } catch(\Extension $e){
             return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500);
        }
    }

    public function remove_cart(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $user_id = $request->user ? $request->user->id : $request['guest_id'];
            $is_guest = $request->user ? 0 : 1;

            $carts = Cart::where('user_id', $user_id)->where('is_guest',$is_guest)->get();

            foreach($carts as $cart){
                $cart->delete();
            }

            $carts = Cart::where('user_id', $user_id)->where('is_guest',$is_guest)->get()
            ->map(function ($data) {
                $data->restaurant_name =$data->restaurant?->name;
                $data->add_on_ids = json_decode($data->add_on_ids,true) ?? [];
                $data->add_on_qtys = json_decode($data->add_on_qtys,true) ?? [];
                $data->variations = json_decode($data->variations,true) ?? [];
                $data->variation_options = json_decode($data->variation_options,true) ?? [];

                $data->item = Helpers::cart_product_data_formatting($data->item, $data->variations, $data->variation_options, $data->add_on_ids,
                $data->add_on_qtys, false, app()->getLocale());
                unset($data->restaurant);
                return $data;
            });
           return response()->json(['status'=>'success','data'=>$carts], 200);

       } catch(\Extension $e){
             return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500);
        }
    }

    public function add_to_cart_multiple(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_list' => 'required',
        ]);

        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

            foreach($request->item_list as $single_item){


                $model = $single_item['model'] === 'Food' ? 'App\Models\Food' : 'App\Models\ItemCampaign';
                $item = $single_item['model'] === 'Food' ? Food::find($single_item['item_id']) : ItemCampaign::find($single_item['item_id']);

                $cart = Cart::where('item_id',$single_item['item_id'])->where('item_type',$model)->where('variations',json_encode($single_item['variations']))->where('user_id', $user_id)->where('is_guest',0)->first();

                if($cart){
                    return response()->json([
                        'errors' => [
                            ['code' => 'cart_item', 'message' => translate('messages.Item_already_exists')]
                        ]
                    ], 403);
                }

                if($item->maximum_cart_quantity && ($single_item['quantity']>$item->maximum_cart_quantity)){
                    return response()->json([
                        'errors' => [
                            ['code' => 'cart_item_limit', 'message' => translate('messages.maximum_cart_quantity_exceeded')]
                        ]
                    ], 403);
                }


                if($single_item['model'] === 'Food'){
                    $addonAndVariationStock= Helpers::addonAndVariationStockCheck(product:$item,quantity: $single_item['quantity'],add_on_qtys:$single_item['add_on_qtys'], variation_options: $single_item['variation_options'],add_on_ids:$single_item['add_on_ids'] );

                        if(data_get($addonAndVariationStock, 'out_of_stock') != null) {
                            return response()->json([
                                'errors' => [
                                    ['code' => 'stock_out', 'message' => data_get($addonAndVariationStock, 'out_of_stock') ],
                                ]
                            ], 403);
                        }
                }

                $cart = new Cart();
                $cart->user_id =$request->user->id;
                $cart->item_id = $single_item['item_id'];
                $cart->is_guest = 0;
                $cart->add_on_ids = json_encode($single_item['add_on_ids']);
                $cart->add_on_qtys = json_encode($single_item['add_on_qtys']);
                $cart->item_type = $single_item['model'];
                $cart->price = $single_item['price'];
                $cart->quantity = $single_item['quantity'];
                $cart->restaurant_id = $single_item['restaurant_id'];
                $cart->variations = json_encode($single_item['variations']);
                $cart->variation_options =  data_get($single_item,'variation_options',[] ) != null ? json_encode(data_get($single_item,'variation_options',[] )) : json_encode([]);

                $cart->save();

                $item->carts()->save($cart);
            }

        $carts = Cart::where('user_id', $user_id)->where('is_guest',0)->get()
        ->map(function ($data) {
            $data->add_on_ids = json_decode($data->add_on_ids, true) ?? [];
            $data->add_on_qtys = json_decode($data->add_on_qtys,true)?? [];
            $data->variations = json_decode($data->variations,true) ?? [];
            $data->variation_options = json_decode($data->variation_options,true) ?? [];

            $data->item = Helpers::cart_product_data_formatting($data->item, $data->variations,$data->variation_options, $data->add_on_ids,
            $data->add_on_qtys, false, app()->getLocale());
            return $data;
        });
        return response()->json($carts, 200);
    }


    private function getCharages($restaurantIds, $request)
    {

        $deliveryCharge = 0;
        $OriginalDeliveryCharge = 0;
        for ($i=0; $i <count($restaurantIds) ; $i++) { 
           
            $restaurant_id=$restaurantIds[$i];
            $restaurant = Restaurant::selectRaw("
                (6371 * acos(
                    cos(radians(?)) * 
                    cos(radians(restaurants.latitude)) *
                    cos(radians(restaurants.longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(restaurants.latitude))
                )) AS distance
            ", [$request->latitude, $request->longitude, $request->latitude])
            ->where('id', $restaurant_id)
            ->first();
 

            $coupon = null;
            $delivery_charge = null;
            $free_delivery_by = null;
            $coupon_created_by = null;
            $schedule_at =\Carbon\Carbon::now();
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
            $maximum_shipping_charge =  0;
            $max_cod_order_amount_value=  0;
            $increased=0;
            $distance_data = 0;


            if (!empty($request->coupon_code)) {
                $coupon = Coupon::active()->where(['code' => $request['coupon_code']])->first();
                if (isset($coupon)) {
                    if($coupon->coupon_type == 'free_delivery'){
                        $delivery_charge = 0;
                    }
                } 
            } 

            $data = Helpers::vehicle_extra_charge(distance_data:$distance_data);
            $extra_charges = (float) (isset($data) ? $data['extra_charge']  : 0);
            $vehicle_id= (isset($data) ? (int) $data['vehicle_id']  : null);

            if($request->latitude && $request->longitude){
                $zone = Zone::where('id', $restaurant->zone_id)->whereContains('coordinates', new Point($request->latitude, $request->longitude, POINT_SRID))->first(); 
                if($zone){       
                   
                    // Assign values safely (even if 0)
                    $per_km_shipping_charge     = $zone->per_km_shipping_charge ?? 0;
                    $minimum_shipping_charge    = $zone->minimum_shipping_charge ?? 0;
                    $maximum_shipping_charge    = $zone->maximum_shipping_charge ?? 0;
                    $max_cod_order_amount_value = $zone->max_cod_order_amount ?? 0;

                    // Increased delivery fee
                    if ((int) $zone->increased_delivery_fee_status === 1) {
                        $increased = $zone->increased_delivery_fee ?? 0;
                    }
                }
            } 

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

            $original_delivery_charge = ($restaurant->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $restaurant->distance * $per_km_shipping_charge + $extra_charges  : $minimum_shipping_charge + $extra_charges;

        
            if(in_array($request['order_type'], ['take_away', 'dine_in']))
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

            $deliveryCharge =$deliveryCharge+round($delivery_charge, config('round_up_to_digit'))??0;
            $OriginalDeliveryCharge =$OriginalDeliveryCharge+round($original_delivery_charge, config('round_up_to_digit'));
        }
        
        $data['delivered_charge']=$deliveryCharge;
        $data['original_delivery_charge']=$OriginalDeliveryCharge;

        return $data;
    }
    
}
