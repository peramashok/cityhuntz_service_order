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
use App\Models\Event;
use App\Models\Vendor;
use App\Models\ReservedTable;
use App\Models\SubscriptionTransaction;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use App\Models\EventsTicket;
use App\Models\PaymentSetting;
use App\Models\EventsPurchasedTicket;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\WalletTransaction;

class EventsPurchaseController extends Controller
{
    public function book_now(Request $request)
    {
        try {

            /* =======================
             * VALIDATION
             * ======================= */
            $validator = Validator::make($request->all(), [

                'guest_id' => $request->user ? 'nullable' : 'required',
                'no_of_persons' => 'required|integer|min:1',
                'event_id' => [
                    'required',
                    Rule::exists('events', 'id')->whereNull('deleted_at')
                ],

                'ticket_id' => [
                    'required',
                    Rule::exists('events_tickets', 'id')->whereNull('deleted_at')
                ],
                'name'=>'required',
                'contact_number'=>'required',
                'email_id'=>'required|email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'errors' => Helpers::error_processor($validator)
                ], 403);
            }

            $ticketData = EventsTicket::where('event_id', $request->event_id)->where('id', $request->ticket_id)->first();
            if(is_null($ticketData)){
                return response()->json([
                    'status' => 'failed',
                    'message' =>"Ticket details not found for this selected event"
                ], 400);  
            }
            
            $eventData = Event::with([
                    'tickets' => function ($q) use ($request) {

                    $q->where('status', 1)

                    ->when($request->filled('ticket_id'), function ($query) use ($request) {
                        $query->where('id', $request->ticket_id);
                    })

                    ->selectRaw("
                        events_tickets.*,
                        COALESCE(
                            (
                                SELECT SUM(no_of_tickets)
                                FROM events_purchased_tickets
                                WHERE events_purchased_tickets.ticket_id = events_tickets.id
                            ), 0
                        ) as purchased_tickets,

                        COALESCE(
                            (
                                SELECT SUM(canceled_tickets_count)
                                FROM events_purchased_tickets
                                WHERE events_purchased_tickets.ticket_id = events_tickets.id
                            ), 0
                        ) as cancelled_tickets,

                        (
                            COALESCE(
                                (
                                    SELECT SUM(no_of_tickets)
                                    FROM events_purchased_tickets
                                    WHERE events_purchased_tickets.ticket_id = events_tickets.id
                                ), 0
                            )

                            -

                            COALESCE(
                                (
                                    SELECT SUM(canceled_tickets_count)
                                    FROM events_purchased_tickets
                                    WHERE events_purchased_tickets.ticket_id = events_tickets.id
                                ), 0
                            )
                        ) as sold_tickets,

                        GREATEST(
                            (
                                events_tickets.total_ticket -

                                (
                                    COALESCE(
                                        (
                                            SELECT SUM(no_of_tickets)
                                            FROM events_purchased_tickets
                                            WHERE events_purchased_tickets.ticket_id = events_tickets.id
                                        ), 0
                                    )

                                    -

                                    COALESCE(
                                        (
                                            SELECT SUM(canceled_tickets_count)
                                            FROM events_purchased_tickets
                                            WHERE events_purchased_tickets.ticket_id = events_tickets.id
                                        ), 0
                                    )
                                )
                            ),
                            0
                        ) as available_tickets
                    ");
                },

                'stateInfo',
                'cityInfo'
            ])

            ->active()
            ->where('id', $request->event_id)
            ->first();
           
            if(is_null($eventData)){
                return response()->json([
                    'status' => 'failed',
                    'message' =>"Event details not found"
                ], 400);  
            }

            // if($eventData->event_type=='FE'){
            //     return response()->json([
            //         'status' => 'failed',
            //         'message' =>"You can’t reserved this event as it is free entry"
            //     ], 400);  
            // }

            if (Carbon::parse($eventData->to_date)->lt(Carbon::today())) {
                 return response()->json([
                    'status' => 'failed',
                    'message' =>"Event was expired"
                ], 400);     
            }

            $ticket = $eventData->tickets->first();

            $availableTickets = $ticket ? $ticket->available_tickets : 0;
            $ticketCost = $ticket ? $ticket->price : 0;
            if ($availableTickets < $request->no_of_persons) {
                return response()->json([
                    'status' => 'failed',
                    'message' => "Only {$availableTickets} tickets are available."
                ], 400);
            }

            $bookingCode = time();


            $paymentSetting=PaymentSetting::where('key', 'platform_fee')->first();
            $platformFee=$paymentSetting->value;

            $total=$request->no_of_persons*$ticketCost;

            $platformAmount=($total*$platformFee)/100;

            $total_amount=$total+$platformAmount;


            $purchardTicket=new EventsPurchasedTicket();
            $purchardTicket->name=$request->name;
            $purchardTicket->contact_number=$request->contact_number;
            $purchardTicket->emailid=$request->email_id;
            $purchardTicket->event_id=$request->event_id;
            $purchardTicket->ticket_id=$request->ticket_id;
            $purchardTicket->no_of_tickets=$request->no_of_persons;
            $purchardTicket->booking_code  = $bookingCode;
            $purchardTicket->user_id       = $request->user ? $request->user->id : $request->guest_id;
            $purchardTicket->is_guest      = $request->user ? 0 : 1;
            $purchardTicket->booking_status = 'pending';
            $purchardTicket->payment_status = 'pending';
            $purchardTicket->ticket_cost = $ticketCost;

            $purchardTicket->booking_amount     = $total;
            $purchardTicket->processing_charges = $platformAmount;
            $purchardTicket->total_amount = $total_amount;


            $purchardTicket->save();
                
            return response()->json([
                'status'       => 'success',
                'message'      => 'You have successfully reserved your event tickets.',
                'booking_id'   => $purchardTicket->id,
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

   
    public function getAllCustomerBookings(Request $request)
    {
         try{
            $validator = Validator::make($request->all(), [
                'guest_id' => $request->user ? 'nullable' : 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['status'=>'failed', 'errors' => Helpers::error_processor($validator)], 403);
            }

            $pagelength = $request->pagelength ?? 10;
            $pageno     = $request->pageno ?? 1;

            $guest_id=$request->user ? $request->user->id : $request->guest_id;
            $is_guest=$request->user ? 0 : 1;
            $reservations = EventsPurchasedTicket::with('event:id,event_name,event_poster,vendor_id','event.vendor:id,f_name,l_name')
                ->where('user_id', $guest_id)
                ->where('is_guest', $is_guest);

             $totalrecords = (clone $reservations)->count();

            $reservations =$reservations->orderBy('id', 'DESC') ->skip(($pageno - 1) * $pagelength)
                ->take($pagelength)                
                ->get();

            $data['data'] = $reservations;
            $data['current_page'] =$pageno ? $pageno : '1';
            $data['total'] = $totalrecords;
            $data['per_page'] = $pagelength ? $pagelength : '10';

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

            $reservation = EventsPurchasedTicket::with('event','ticket', 'canceled_tickets')->where('id', $id)->where('user_id', $guest_id)->where('is_guest', $is_guest)->first();
            
             return response()->json([
               'status' => 'success',
               'data' => $reservation 
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
