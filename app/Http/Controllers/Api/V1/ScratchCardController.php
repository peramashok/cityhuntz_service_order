<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Coupon;
use Api\Models\UserScratchCard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ScratchCardController extends Controller
{
   /**
    * generate scratch cards
    */

   public function generateScratchCard(Request $request)
   {
        try{
            $validator = Validator::make($request->all(), [
               'order_ids'   => 'required|array|min:1',
               'order_ids.*' => 'required|integer|exists:orders,id' 
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 422);
            }

            $orderResults = Order::whereIn('id', $request->order_ids)
                ->where('user_id', auth()->id())
                ->get();

            if (count($orderResults)==0) {
                 return response()->json([
                   'status' => 'failed',
                   'message' => "No order details found" 
                 ], 400); 
            }

           $restaurantIds =  Order::whereIn('id', $request->order_ids)
                ->where('user_id', auth()->id())
                ->pluck('restaurant_id')
                ->unique()
                ->toArray();

            $coupons = Coupon::whereIn('restaurant_id', $restaurantIds)
                ->where('status', 1)
                ->get();

            // 30% chance to win
            $chance = rand(1, 100);

            if ($chance <= 30) {
                $coupon = Coupon::whereIn('restaurant_id', $restaurantIds)
                    ->where('status', 1)
                    ->where('coupon_type', 'scratch_card')
                    ->inRandomOrder()
                    ->first();

                if ($coupon) {

                    $scrtchCard=new UserScratchCard();
                    $scrtchCard->user_id=auth()->user()->id;
                    $scrtchCard->restaurant_id=$coupon->restaurant_id;

                    $scrtchCard->title=$coupon->title;
                    $scrtchCard->code=$coupon->code;
                    $scrtchCard->min_purchase=$coupon->min_purchase;
                    $scrtchCard->max_discount=$coupon->max_discount;

                    $scrtchCard->title=$coupon->discount;
                    $scrtchCard->code=$coupon->discount_type;
                    $scrtchCard->limit=1;
                    $scrtchCard->start_date=Carbon::today();
                    $scrtchCard->expire_date=Carbon::today()->addMonths(2);
                    $scrtchCard->save();

                    return response()->json([
                        'status' => 'win',
                        'coupon' => $coupon
                    ]);
                }
            }

            return response()->json([
                'status' => 'lose',
                'coupon' => null
            ]);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }

   /**
    * get all customer coupons list
    * @param Illuminate\Http\Request
    * @return Illuminate\Http\Response
    */
   public function get_customer_order_list(Request $request)
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

            $user = auth()->user();
           
            $paginator = UserScratchCard::with('customer','restaurant', 'restaurant.vendor')
            ->when($request->status == 'all', function($query){
                return $query->where('status', $request->status);
            })
            ->latest()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);
            
            $data = [
                'total_size' => $paginator->total(),
                'limit' => $request['limit'],
                'offset' => $request['offset'],
                'data' => $orders
            ];
            return response()->json(['status'=>'success', 'data'=>$data], 200);
        } catch(\Extension $e){
             return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500);
        }
   }
}
