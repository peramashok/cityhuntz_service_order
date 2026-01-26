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
use App\Models\Restaurant;
use App\Models\Vendor;
use App\Models\ReservedTable;
use App\Models\SubscriptionTransaction;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use App\Models\RestaurantTable;
use App\Models\PaymentSetting;
use App\Models\ReservedTableDetail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BookATableController extends Controller
{
    public function book_now(Request $request)
    {
        try {

            /* =======================
             * VALIDATION
             * ======================= */
            $validator = Validator::make($request->all(), [

                'guest_id' => $request->user ? 'nullable' : 'required',

                'table_nos' => [
                    'required',
                    'regex:/^\d+(,\d+)*$/',
                    function ($attribute, $value, $fail) {
                        $ids = explode(',', $value);

                        if (
                            DB::table('restaurant_tables')
                                ->whereIn('id', $ids)
                                ->count() !== count($ids)
                        ) {
                            $fail('One or more table numbers are invalid.');
                        }
                    }
                ],

                // ✅ DATE ONLY
                'schedule_at' => 'required|date_format:Y-m-d',

                // ✅ TIME RANGE
                'from_time' => 'required|date_format:H:i:s',
                'to_time'   => 'required|date_format:H:i:s|after:from_time',

                'no_of_persons' => 'required|integer|min:1',

                'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ],
            ]);


            
            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'errors' => Helpers::error_processor($validator)
                ], 403);
            }

            /* =======================
             * COMBINE DATE + TIME ✅
             * ✅ FIXED HERE
             * ======================= */
            $schedule_at = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $request->schedule_at . ' ' . $request->from_time
            );

            if ($schedule_at->isPast()) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Schedule time must be today or a future time.'
                ], 403);
            }

            /* =======================
             * RESTAURANT OPEN CHECK
             * ======================= */
            $restaurant = Restaurant::with(['discount', 'restaurant_sub'])
                ->selectRaw(
                    '
                    restaurants.*,
                    IF(
                        (
                            SELECT COUNT(*) 
                            FROM restaurant_schedule 
                            WHERE restaurants.id = restaurant_schedule.restaurant_id
                            AND restaurant_schedule.day = ?
                            AND restaurant_schedule.opening_time <= ?
                            AND restaurant_schedule.closing_time >= ?
                        ) > 0,
                        true,
                        false
                    ) AS open
                    ',
                    [
                        $schedule_at->format('w'),
                        $schedule_at->format('H:i:s'),
                        $schedule_at->format('H:i:s'),
                    ]
                )
                ->where('id', $request->restaurant_id)
                ->first();

            if (!$restaurant) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => translate('messages.restaurant_not_found')
                ], 404);
            }

            if (
                ($restaurant->restaurant_model === 'subscription' && isset($rest_sub)) ||
                ($restaurant->restaurant_model === 'unsubscribed')
            ) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => translate('messages.Sorry_the_restaurant_is_unable_to_take_any_order_!')
                ], 403);
            }

            if (!$restaurant->active) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Restaurant is temporarily closed'
                ], 403);
            }

            if (!$restaurant->open) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => translate('messages.restaurant_is_closed_at_order_time')
                ], 403);
            }

           
             
            $fromTime = $request->from_time;
            $toTime   = $request->to_time;
            $scheduledDate = Carbon::parse($request->schedule_at)->toDateString();

            $selectedTableNos = explode(',', $request->table_nos); // "6,7" → [6,7]

            // $tableslist = RestaurantTable::whereIn('restaurant_tables.id', $selectedTableNos)
            //     ->leftJoin('reserved_tables', function ($join) use ($fromTime, $toTime, $scheduledDate) {
            //         $join->on(DB::raw('FIND_IN_SET(restaurant_tables.id, reserved_tables.table_nos)'), '>', DB::raw('0'))
            //             ->whereDate('reserved_tables.scheduled_at', $scheduledDate)
            //             ->where(function ($q) use ($fromTime, $toTime) {
            //                 $q->where('reserved_tables.from_time', '<', $toTime)
            //                   ->where('reserved_tables.to_time', '>', $fromTime);
            //             });
            //     })
            //     ->select(
            //         'restaurant_tables.id',
            //         'restaurant_tables.table_name',
            //         'restaurant_tables.capacity',
            //         DB::raw('IF(COUNT(reserved_tables.id) > 0, 1, 0) as is_booked')
            //     )
            //     ->groupBy(
            //         'restaurant_tables.id',
            //         'restaurant_tables.table_name',
            //         'restaurant_tables.capacity'
            //     )
            //     ->get();

    
            $tableCounts=RestaurantTable::whereIn('restaurant_tables.id', $selectedTableNos)->where('restaurant_id', $request->restaurant_id)->count();

            if($tableCounts<count($selectedTableNos)){
                 return response()->json([
                    'status' => 'failed',
                    'message'=>"Selected table of Restaurant not belongs to selected restaurant",
                ], 400);
            }

             $tableslist = RestaurantTable::whereIn('restaurant_tables.id', $selectedTableNos)
                ->leftJoin('reserved_tables', function ($join) use ($fromTime, $toTime, $scheduledDate) {

                    $join->on(DB::raw('FIND_IN_SET(restaurant_tables.id, reserved_tables.table_nos)'), '>', DB::raw('0'))

                        // ✅ active reservations only
                        ->whereNotIn('reserved_tables.order_status', ['cancelled', 'closed'])

                        // ✅ same date
                        ->whereDate('reserved_tables.scheduled_at', $scheduledDate)

                        // ✅ time overlap logic
                        ->where(function ($q) use ($fromTime, $toTime) {
                            $q->where('reserved_tables.from_time', '<', $toTime)
                              ->where('reserved_tables.to_time', '>', $fromTime);
                        });
                })
                ->select(
                    'restaurant_tables.id',
                    'restaurant_tables.table_name',
                    'restaurant_tables.capacity',

                    // ✅ if NO active overlapping reservation → 0
                    DB::raw('IF(COUNT(reserved_tables.id) > 0, 1, 0) AS is_booked')
                )
                ->groupBy(
                    'restaurant_tables.id',
                    'restaurant_tables.table_name',
                    'restaurant_tables.capacity'
                )
                ->get();
     
                if(count($tableslist)>0){
                    foreach($tableslist as $row){
                        if (in_array($row->id, $selectedTableNos) && $row->is_booked==1) {

                             return response()->json([
                                'status'       => 'failed',
                                'message'      => "table no : ".$row->table_name. " is booked on ".$request->schedule_at." . So please choose another table",
                            ], 400);
                        }
                    }
                }



            /* =======================
             * BOOK TABLE ✅
             * ======================= */
            $bookingCode = time();

            $bookATable = new ReservedTable();
            $bookATable->booking_code  = $bookingCode;
            $bookATable->user_id       = $request->user ? $request->user->id : $request->guest_id;
            $bookATable->is_guest      = $request->user ? 0 : 1;
            $bookATable->scheduled_at  = $schedule_at;   // ✅ VALID DATETIME
            $bookATable->from_time     = $request->from_time;
            $bookATable->to_time       = $request->to_time;
            $bookATable->table_nos     = $request->table_nos;
            $bookATable->no_of_persons = $request->no_of_persons;
            $bookATable->restaurant_id = $request->restaurant_id;
            $bookATable->order_status = 'pending';
            $bookATable->pending      = now();
            $bookATable->created_at   = now();
            $bookATable->save();
            $bookATable->order_status = 'pending';
            $bookATable->pending      = now();
            $bookATable->created_at   = now();
            $bookATable->save();


            $tablesList=RestaurantTable::whereIn('restaurant_tables.id', $selectedTableNos)->where('restaurant_id', $request->restaurant_id)->get();
            $total=0;
            foreach($tablesList as $single){
                $ReservedTableData = new ReservedTableDetail();
                $ReservedTableData->order_id=$bookATable->id;
                $ReservedTableData->table_id=$single->id;
                $ReservedTableData->table_name=$single->table_name;
                $ReservedTableData->amount=$single->price;
                $ReservedTableData->status='Booked';
                $ReservedTableData->save();
                $total=$total+$single->price;
            }

            $paymentSetting=PaymentSetting::where('id', 1)->first();
            $platformFee=$paymentSetting->platform_fee;

            $platformAmount=($total*$platformFee)/100;

            $total_amount=$total+$platformAmount;

            $bookATableUpdate =ReservedTable::findOrFail($bookATable->id);
            $bookATable->order_amount     = $total;
            $bookATable->processing_charges = $platformAmount;
            $bookATable->total_amount = $total_amount;
            $bookATable->save();



            return response()->json([
                'status'       => 'success',
                'message'      => 'You have successfully reserved your tables.',
                'booking_id'   => $bookATable->id,
                'booking_code' => $bookingCode,
                'total_amount'=>$total_amount
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => 'failed',
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getBookedTableDetails($id, Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            $guest_id=$request->user ? $request->user->id : $request->guest_id;
            $is_guest=$request->user ? 0 : 1;

            $reservation = ReservedTable::with('table_details', 'table_details.restaurantTables')->where('id', $id)->where('user_id', $guest_id)->where('is_guest', $is_guest)->first();
            $tableIds = explode(',', $reservation->table_nos);

            $tables = RestaurantTable::whereIn('id', $tableIds)->get();

             return response()->json([
               'status' => 'success',
               'data' => ['reservation'=>$reservation] 
             ], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getLine()." ".$e->getMessage()
             ], 500);
        }
    }


    public function getAllCustomerBookings(Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
            $guest_id=$request->user ? $request->user->id : $request->guest_id;
            $is_guest=$request->user ? 0 : 1;
                $reservations = ReservedTable::with('restaurant:id,name,logo')
                ->where('user_id', $guest_id)
                ->where('is_guest', $is_guest)
                ->get()
                ->map(function ($item) {
                    // Convert CSV table_nos to array
                    $tableIds = explode(',', $item->table_nos);

                    // Fetch all tables for this reservation
                    $item->tables = RestaurantTable::whereIn('id', $tableIds)->get();

                    return $item; // make sure to return the modified item
                });
             return response()->json([
               'status' => 'success',
               'data' => ['tables_list'=>$reservations] 
             ], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getLine()." ".$e->getMessage()
             ], 500);
        }
    }


    /**
     * get all vendor current orders
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Response
     */
    public function getAllVendorCurrrentBookings(Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
               'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ]
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
           
            $reservations = ReservedTable::with('customer:id,f_name,l_name,email,phone')
                ->where('restaurant_id', $request->restaurant_id)
                ->whereIn('order_status', ['pending','confirmed','dine_in','cancelled'])
                ->orderBy('from_time', 'DESC')
                ->get()
                ->map(function ($item) {
                    // Convert CSV table_nos to array
                    $tableIds = explode(',', $item->table_nos);

                    // Fetch all tables for this reservation
                    $item->tables = RestaurantTable::whereIn('id', $tableIds)->get();

                    return $item; // make sure to return the modified item
                });
             return response()->json([
               'status' => 'success',
               'data' => ['tables_list'=>$reservations] 
             ], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getLine()." ".$e->getMessage()
             ], 500);
        }
    }


     /**
     * get all vendor current orders
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Response
     */
    public function getAllVendorBookings(Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
               'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ],
                'limit'=>'required|integer',
                'offset'=>'required|integer'
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
           
           $reservations = ReservedTable::with('customer:id,f_name,l_name,email,phone,image')
                ->where('restaurant_id', $request->restaurant_id)
                ->orderBy('id', 'desc')
                ->paginate($request['limit'], ['*'], 'page', $request['offset']);

           $reservationsItems = collect($reservations->items())->map(function ($item) {
                $tableIds = array_filter(explode(',', $item->table_nos));
                $item->tables = RestaurantTable::whereIn('id', $tableIds)->get();
                return $item;
            });

            $data = [
                'total_size' => $reservations->total(),
                'limit' => $request['limit'],
                'offset' => $request['offset'],
                'bookings' => $reservationsItems
            ];

            return response()->json([
               'status' => 'success',
               'data' => $data
            ], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getLine()." ".$e->getMessage()
             ], 500);
        }
    }

     /**
     * get all vendor closed bookings
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Response
     */
    public function getAllVendorClosedBookings(Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
               'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ]
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }
           
           $reservations = ReservedTable::with('customer:id,f_name,l_name,email,phone,image')
                ->where('restaurant_id', $request->restaurant_id)
                ->where('order_status', 'closed')
                ->orderBy('id', 'desc')
                ->paginate($request['limit'], ['*'], 'page', $request['offset']);

           $reservationsItems = collect($reservations->items())->map(function ($item) {
                $tableIds = array_filter(explode(',', $item->table_nos));
                $item->tables = RestaurantTable::whereIn('id', $tableIds)->get();
                return $item;
            });

            $data = [
                'total_size' => $reservations->total(),
                'limit' => $request['limit'],
                'offset' => $request['offset'],
                'bookings' => $reservationsItems
            ];

            return response()->json([
               'status' => 'success',
               'data' => $data
            ], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getLine()." ".$e->getMessage()
             ], 500);
        }
    }

    /**
     * get detailed booking details
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Response
     */
    public function getVendorBookedTableDetails($id, Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
               'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ]
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }

            $reservation = ReservedTable::with('customer:id,f_name,l_name,email,phone,image', 'table_details')->where('id', $id)->first();

            if(is_null($reservation)){
                 return response()->json([
                   'status' => 'failed',
                   'message'=>'Booking details not found'
                 ], 400);
            }
            if($reservation->restaurant_id!=$request->restaurant_id){
                 return response()->json([
                   'status' => 'failed',
                   'message'=>"You can’t view other restaurant's booking details"
                 ], 400);
            }

            $tableIds = explode(',', $reservation->table_nos);
            $tables = RestaurantTable::whereIn('id', $tableIds)->get();

             return response()->json([
               'status' => 'success',
               'data' => ['reservation'=>$reservation, 'tables_list'=>$tables] 
             ], 200);

         } catch(\Exception $e){
              return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
                'error'=>$e->getLine()." ".$e->getMessage()
             ], 500);
        }
    }


    /**
     * update bookings status
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
    */
    public function updateBookingStatus(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'restaurant_id' => [
                    'required',
                    Rule::exists('restaurants', 'id')->whereNull('deleted_at')
                ],
                'booking_id' => 'required|exists:reserved_tables,id',
                'reason' =>'required_if:status,cancelled',
                'status' => 'required|in:confirmed,cancelled,closed,dine_in',
                //'order_proof' =>'nullable|array|max:5',
            ]);
            $request->otp="123456";
            // $validator->sometimes('otp', 'required', function ($request) {
            //     return (Config::get('order_delivery_verification')==1 && $request['status']=='delivered');
            // });

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $vendor = auth()->user();
            $order = ReservedTable::with('restaurant')->where('id', $request->booking_id)->where('restaurant_id', $request->restaurant_id)->first();
            if(!$order)
            {
                return response()->json([
                    'status'=>'failed',
                    'code' => 'booking', 
                    'message' => 'booking details not found'
                ], 403);
            }

             $restaurant=Restaurant::where('id', $request->restaurant_id)->first();

            if($restaurant->vendor_id!=$vendor->id){
                return response()->json([
                    'status'=>'failed',
                    'code' => 'booking', 
                    'message' => "You can’t change status of other restaurant's bookings"
                ], 403);
            }

            $restaurant=$order->restaurant;
            // $data =0;
            // if ($restaurant?->restaurant_model == 'subscription'){
            //   $data =1;
            // }
 
            

            $order->order_status = $request['status'];
            if($request->status=='cancelled'){
                $order->cancelled_reason = $request['reason'];
                $order->cancelled_by = 'restaurant';
            }
            $order[$request['status']] = now();
            $order->save();

            if($request->status=='closed' ){
                $tranArray=array(
                    "user_id"=>$vendor->id,
                    "transaction_id"=>"R".uniqid('', true),
                    "credit"=>round($order->total_amount-$order->processing_charges,2),
                    "transaction_type"=>'booking',
                    "reference"=>$vendor->phone,
                    "order_id"=>$order->id,
                    "restaturant_id"=>$order->restaurant_id,
                    "created_at"=>now()
                );

                WalletTransaction::create($tranArray);
            }

            if($request->status=='cancelled'){
                try {
                    $response = Http::post(
                        env('PAYMENT_URL') . 'refunds/booking_refund',
                        [
                            'booking_id' => $order->id,
                            'amount'=>$order->total_amount 
                        ]
                    );

                     return response()->json(['status'=>'failed','message' => $response], 400);

                } catch (\Exception $th) {
                    Log::error($ex->getMessage());
                }
            }
            try{
                $response = Http::post(
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

            
           // Helpers::send_order_notification($order);

            return response()->json(['status'=>'success','message' => 'You have successfully updated booking status into '. $request->status], 200);

        } catch(\Extension $e){
             return response()->json([
                   'status' => 'failed',
                   'message' => "Something went wrong. ",
                   'error'=>$e->getMessage()
                 ], 500);
        }
    }



     /**
     * cancel placed order
     * @param Illuminate\Http\Request
     * @return Illuminate\Http\Resp
     */
     public function cancel_booking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|max:255',
            'guest_id' => $request->user ? 'nullable' : 'required',
            'booking_id'=>'required|exists:reserved_tables,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
        }
        $user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order = ReservedTable::where(['user_id' => $user_id, 'id' => $request['booking_id']])

        ->when(!isset($request->user) , function($query){
            $query->where('is_guest' , 1);
        })

        ->when(isset($request->user)  , function($query){
            $query->where('is_guest' , 0);
        })
        ->first();
        if(!$order){
                return response()->json(['status'=>'failed', 'code' => 'order', 'message' => translate('messages.not_found')], 400);
        }
        else if ($order->order_status != 'closed') {
            $order->order_status = 'cancelled';
            $order->cancelled = now();
            $order->cancelled_reason = $request->reason;
            $order->cancelled_by = 'customer';
            $order->save();

             try {
                $response = Http::post(
                    env('PAYMENT_URL') . 'refunds/booking_refund',
                    [
                        'booking_id' => $order->id,
                        'amount'=>$order->total_amount
                    ]
                );

                dd($response);
            } catch (\Exception $th) {
                Log::error($th->getMessage());
            }

            try{
                $response = Http::post(
                    env('NOTIFICATION_URL') . 'notifications/update_booking_status',
                    [
                        'booking_id' => $order->id,
                        'status'=>'cancelled'
                    ]
                );
            }catch(\Exception $ex){
                \Log::error('Notification API failed', [
                    'message' => $ex->getMessage(),
                    'booking_id' => $order->id,
                ]); 
            }
            return response()->json(['status'=>'success', 'message' => translate('messages.order_canceled_successfully')], 200);
        }
        return response()->json(['status'=>'failed', 'code' => 'order', 'message' => translate('messages.you_can_not_cancel_after_confirm')], 403);
    }
}
