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

            return response()->json([
                'status'       => 'success',
                'message'      => 'You have successfully reserved your tables.',
                'booking_id'   => $bookATable->id,
                'booking_code' => $bookingCode
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

            $reservation = ReservedTable::where('id', $id)->where('user_id', $guest_id)->where('is_guest', $is_guest)->first();
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
}
