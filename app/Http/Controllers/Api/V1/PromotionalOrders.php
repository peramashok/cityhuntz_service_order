<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderDetail;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Restaurant;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PromotionalOrders extends Controller
{
       /**
     * Modify influencer details
     */
    public function createRestaurantNewOrder(Request $request)
    {
         $validator = Validator::make($request->all(), [
            "order_type"=>'required|in:dine_in,take_away',
            "vendor_id"=>'required',
            "restaurant_id"=>'required',
            'order_amount' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'failed','errors' => Helpers::error_processor($validator)], 422);
        }
        try{


           $restaurant = Restaurant::with('vendor')->find($request->restaurant_id);

            if (!$restaurant) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => "Restaurant not found."
                ], 404);
            }

            if ($restaurant->vendor_id != $request->vendor_id) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => "The specified vendor does not belong to this restaurant."
                ], 403);
            }


             if (
                in_array($restaurant->is_high_visibility_premium, ['0', '2']) ||
                ($restaurant->is_high_visibility_premium == '3' && Carbon::parse($restaurant->hp_expiry_date)->lt(Carbon::today()))
            ) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => "You can't do payment as this restaurant not an active premium marketer"
                ], 400);
            }


            $user=auth()->user();
            
            $paymentArray=Helpers::getWalletAmount($user->id);
            if($paymentArray['status']=='failed'){
                 return response()->json([
                    'status'  => 'failed',
                    'message' => "Something went wrong"
                ], 400);
            }
  
            $availableAmountArray=$paymentArray['available_amount'];
            $availableAmount=$availableAmountArray['available_amount_to_withdraw'];

            $availableAmount = (float) $availableAmount;
            $orderAmount  = (float) $request->order_amount;

            
            if ($availableAmount <= 0) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => "There is no eligible amount to do payment"
                ], 400);
            }


            if ($orderAmount > $availableAmount) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => "There is no enough amount to do payment"
                ], 400);
            }


            $address = [
            'contact_person_name' => $user->f_name . ' ' . $user->l_name,
            'contact_person_number' =>$user->phone,
            'contact_person_email' => $user->email,
            'address_type' =>'',
            'address' => '',
            'floor' => '',
            'road' => "",
            'house' =>  "",
            'longitude' => "",
            'latitude' =>  "",
        ];


            $order=new Order();
            $orderId = time();
            $order->order_no = $orderId;
           // $order->vendor_type =$request->vendor_type;
            $order->user_id =$user->id;  
            $order->order_type =$request->order_type; 
            $order->restaurant_discount_amount =0;
            $order->restaurant_id =$request->restaurant_id;
            $order->order_amount =$request->order_amount;
            $order->payment_status='paid';
            $order->order_status='delivered';
            $order->delivery_address = json_encode($address);
            $order->payment_method='wallet';
            $order->save();

            if($order){
                //Paid amount to vendor
                 $tranArray=array(
                    "user_id"=>$request->vendor_id,
                    "transaction_id"=>"R".uniqid('', true),
                    "credit"=>$order->order_amount,
                    "transaction_type"=>'orders',
                    "payment_type"=>'W',
                    "reference"=>$user->phone,
                    "order_id"=>$order->id,
                    "restaturant_id"=>$order->restaurant_id,
                    "transaction_desc"=>'Dine in payment done at restaurant by '.$user->f_name,
                    "created_at"=>now()
                );
                 WalletTransaction::create($tranArray);

                 //debit amount from user or influencer
                 $tranArray=array(
                    "user_id"=>$user->id,
                    "transaction_id"=>"R".uniqid('', true),
                    "debit"=>$request->order_amount,
                    "transaction_type"=>'orders',
                    "payment_type"=>'W',
                    "reference"=>$user->phone,
                    "order_id"=>$order->id,
                    "restaturant_id"=>$order->restaurant_id,
                    "transaction_desc"=>'Dine in payment done at restaurant',
                    "created_at"=>now()
                );

                
                WalletTransaction::create($tranArray);


                 try{
                    $url = rtrim(env('NOTIFICATION_URL'), '/') . '/notifications/send_paid_at_restaurant';

                    $response = Http::asJson()
                        ->acceptJson()
                        ->withOptions([
                            'timeout' => 30,
                        ])
                        ->post($url, [
                            'order_id' => (string) $order->id,
                        ]);
                    if ($response->failed()) {
                        \Log::error('Approve or reject failed', [
                            'status' => $response->status(),
                            'body'   => $response->body(),
                        ]);
                    }
                }catch(\Exception $ex){
                     Log::error($ex->getMessage());
                }

            
                return response()->json(['status'=>'success','message' =>"You have successfully paid amount", 'order_id'=>$order->id], 200);

            } else {
                return response()->json(['status'=>'failed','message' =>"Failed to create order information"], 400);
            }

        } catch(\Exception $e){
            return response()->json([
                'status'=>'failed',
                'error'=>$e->getMessage()." at line number ".$e->getLine(). " in page ".$e->getFile()
            ], 500);
        }
    }
}
