<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ReservedTable;
use App\Models\ReservedTableDetail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Http;
use App\CentralLogics\Helpers;

class HelpCenterController extends Controller
{ 

 /** 
 * get latest 3 orders
 */
   public function getLatestCustomerOrders($customerId)
   {
        try{
            
            $orders = Order::with(['restaurant', 'delivery_man', 'customer:id,f_name,l_name,email,phone'])->where('user_id', $customerId)
                ->orderBy('id', 'desc')
                ->take(3)
                ->get();

            return response()->json(['status'=>'success', 'data'=>$orders], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }

   /** 
     * get  order details
   */
   public function getOrderData($orderId)
   {
        try{
            $order = Order::with(['restaurant:id,name,email,phone', 'details', 'customer:id,f_name,l_name,email,phone'])->where('id', $orderId) ->first();
            return response()->json(['status'=>'success', 'data'=>$order], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }


   /** 
     * cancel order 
    */
   public function cancelOrder(Request $request)
   {
        try{  

             $validator = Validator::make($request->all(), [
                'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ],
                'order_id' => 'required|exists:orders,id',
                'reason' =>'required',
                'status' => 'required|in:canceled',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

 
            $order = Order::where('id', $request->order_id) ->first();

            if(!$order)
            {
                return response()->json([
                    'status'=>'failed',
                    'code' => 'booking', 
                    'message' => 'Order  details not found'
                ], 403);
            }
            $order->order_status = $request['status'];
            $order->cancellation_reason = $request['reason'];
            $order->canceled_by = 'restaurant';
            $order[$request['status']] = now();
            $order->save();
             if($request->status=='canceled'){
                try {
                    $response1 = Http::post(
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
            }
            try{
                $response2 = Http::post(
                    env('NOTIFICATION_URL') . 'notifications/update_status',
                    [
                        'order_id' => $order->id 
                    ]
                );
            }catch(\Exception $ex){
                \Log::error('Notification API failed', [
                    'message' => $ex->getMessage(),
                    'order_id' => $order->id,
                ]); 
            }

            return response()->json(['status'=>'success', 'data'=>$order], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }


 /** 
 * get latest 3 orders
 */
   public function getLatestCustomerReservedTables($customerId)
   {
        try{   
            $tables = ReservedTable::with(['restaurant:id,name,email,phone', 'customer:id,f_name,l_name,email,phone'])->where('user_id', $customerId)
                ->orderBy('id', 'desc')
                ->take(3)
                ->get();

            return response()->json(['status'=>'success', 'data'=>$tables], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }

   public function getReservedTableData($bookingId)
   {
        try{   
            $table = ReservedTable::with(['restaurant:id,name,email,phone','table_details'])->where('id', $bookingId)->first();
            return response()->json(['status'=>'success', 'data'=>$table], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }


   /** 
 * cancel order 
 */
   public function cancelBooking(Request $request)
   {
        try{   
             $validator = Validator::make($request->all(), [
                'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ],
                'booking_id' => 'required|exists:reserved_tables,id',
                'reason' =>'required',
                'status' => 'required|in:cancelled',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

             $order = ReservedTable::with('restaurant')->where('id', $request->booking_id)->where('restaurant_id', $request->restaurant_id)->first();
                if(!$order)
                {
                    return response()->json([
                        'status'=>'failed',
                        'code' => 'booking', 
                        'message' => 'booking details not found'
                    ], 403);
                }
                $order->order_status = $request['status'];
                $order->cancelled_reason = $request['reason'];
                $order->cancelled_by = 'restaurant';
                $order[$request['status']] = now();
                $order->save();


             if($request->status=='cancelled'){
                try {
                    $response1 = Http::post(
                        env('PAYMENT_URL') . 'refunds/booking_refund',
                        [
                            'booking_id' => $order->id,
                            'amount'=>$order->total_amount 
                        ]
                    );
                } catch (\Exception $th) {
                    Log::error($ex->getMessage());
                }
            }
            try{
                $response2 = Http::post(
                    env('NOTIFICATION_URL') . 'notifications/update_booking_status',
                    [
                        'booking_id' => $order->id,
                        'status'=>$request['status']
                    ]
                );
            }catch(\Exception $ex){
                \Log::error('Notification API failed', [
                    'message' => $ex->getMessage(),
                    'booking_id' => $order->id,
                ]); 
            }

            return response()->json(['status'=>'success', 'data'=>"You have successfully cancelled reserved table"], 200);
        } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getLine()."-".$e->getMessage()
             ], 500);
        }
   }
}
