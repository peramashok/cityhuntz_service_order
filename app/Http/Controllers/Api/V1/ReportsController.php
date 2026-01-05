<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Zone;
use App\Exports\OrderReportExport;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Restaurant;
use App\CentralLogics\Helpers;

class ReportsController extends Controller
{
     /**
      * Get orders list for reports
      * @param Illuminate\Http\Request
      * @return Illuminate\Http\Response
      */
      public function order_report(Request $request){
        try{
            
            $from = null;
            $to   = null;
            $filter = $request->query('filter', 'all_time');

            if ($filter === 'custom') {
                $from = $request->from ?? null;
                $to   = $request->to ?? null;
            }

            $key = explode(' ', $request['search'] ?? '');

            $zone_id = $request->query('zone_id', 'all');
            $zone = is_numeric($zone_id) ? Zone::findOrFail($zone_id) : null;

            $restaurant_id = $request->query('restaurant_id', 'all');
            $restaurant = is_numeric($restaurant_id) ? Restaurant::findOrFail($restaurant_id) : null;

            $customer_id = $request->query('customer_id', 'all');
            $customer = is_numeric($customer_id) ? User::findOrFail($customer_id) : null;

            /*
            |--------------------------------------------------------------------------
            | Base Orders Query (DO NOT MODIFY AFTER THIS)
            |--------------------------------------------------------------------------
            */
            $baseOrders = Order::with(['customer', 'restaurant', 'details', 'transaction'])
                ->when($zone, fn ($q) => $q->where('zone_id', $zone->id))
                ->when($restaurant, fn ($q) => $q->where('restaurant_id', $restaurant->id))
                ->when($customer, fn ($q) => $q->where('user_id', $customer->id))
                ->applyDateFilterSchedule($filter, $from, $to);

            /*
            |--------------------------------------------------------------------------
            | Counts (ALWAYS CLONE)
            |--------------------------------------------------------------------------
            */
           

            $total_canceled_count = (clone $baseOrders)
                ->where('order_status', 'canceled')
                ->count();

            $total_delivered_count = (clone $baseOrders)
                ->where('order_status', 'delivered')
                ->where('order_type', '<>', 'pos')
                ->count();

            $total_progress_count = (clone $baseOrders)
                ->whereIn('order_status', ['confirmed', 'processing', 'handover'])
                ->count();

            $total_failed_count = (clone $baseOrders)
                ->where('order_status', 'failed')
                ->count();

            $total_refunded_count = (clone $baseOrders)
                ->where('order_status', 'refunded')
                ->count();

            $total_on_the_way_count = (clone $baseOrders)
                ->whereIn('order_status', ['picked_up'])
                ->count();

            $total_accepted_count = (clone $baseOrders)
                ->where('order_status', 'accepted')
                ->count();

            $total_pending_count = (clone $baseOrders)
                ->where('order_status', 'pending')
                ->count();

            /* Scheduled = future orders */
            $total_scheduled_count = (clone $baseOrders)
                ->whereNotNull('schedule_at')
                ->where('schedule_at', '>', now())
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */
            $pageno = (int) ($request->pageno ?? 1);
            $pagelength = (int) ($request->pagelength ?? 10);

            /*
            |--------------------------------------------------------------------------
            | Orders List
            |--------------------------------------------------------------------------
            */

            $baseOrders =   (clone $baseOrders)
                ->when(isset($key), function($query) use($key){
                    $query->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('id', 'like', "%{$value}%");
                        }
                    });
                });
            $totalrecords=(clone $baseOrders)->count();
            $ordersList = (clone $baseOrders)
                ->orderBy('schedule_at', 'desc')
                ->skip(($pageno - 1) * $pagelength)
                ->take($pagelength)
                ->get();

            $data=[
                'orders'                  => $ordersList,
               
                'zone'                    => $zone,
                'restaurant'              => $restaurant,
                'from'                    => $from,
                'to'                      => $to,
                'total_accepted_count'    => $total_accepted_count,
                'total_pending_count'     => $total_pending_count,
                'total_scheduled_count'   => $total_scheduled_count,
                'filter'                  => $filter,
                'customer'                => $customer,
                'total_on_the_way_count'  => $total_on_the_way_count,
                'total_refunded_count'    => $total_refunded_count,
                'total_failed_count'      => $total_failed_count,
                'total_progress_count'    => $total_progress_count,
                'total_canceled_count'    => $total_canceled_count,
                'total_delivered_count'   => $total_delivered_count,
                'current_page'=>$pageno ? $pageno : '1',
                'total'=>$totalrecords,
                'per_page'=>$pagelength ? $pagelength : '10'
            ];
 
             return response()->json(['status' => 'success', 'data' => $data], 200);
        
         } catch(\Exception $e){
             return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500); 
         }
    }

     /**
      * Get orders list for reports
      * @param Illuminate\Http\Request
      * @return Illuminate\Http\Response
      */

    public function order_report_export(Request $request)
    {
        try{
            $key = isset($request['search']) ? explode(' ', $request['search']) : [];

            $from =  null;
            $to = null;
            $filter = $request->query('filter', 'all_time');
            if($filter == 'custom'){
                $from = $request->from ?? null;
                $to = $request->to ?? null;
            }
            $zone_id = $request->query('zone_id', isset(auth('admin')?->user()?->zone_id) ? auth('admin')?->user()?->zone_id : 'all');
            $zone = is_numeric($zone_id) ? Zone::findOrFail($zone_id) : null;
            $restaurant_id = $request->query('restaurant_id', 'all');
            $restaurant = is_numeric($restaurant_id) ? Restaurant::findOrFail($restaurant_id) : null;
            $customer_id = $request->query('customer_id', 'all');
            $customer = is_numeric($customer_id) ? User::findOrFail($customer_id) : null;
            $filter = $request->query('filter', 'all_time');

            $orders = Order::with(['customer', 'restaurant'])
                ->when(isset($zone), function ($query) use ($zone) {
                    return $query->where('zone_id', $zone->id);
                })
                ->when(isset($restaurant), function ($query) use ($restaurant) {
                    return $query->where('restaurant_id', $restaurant->id);
                })
                ->when(isset($customer), function ($query) use ($customer) {
                    return $query->where('user_id', $customer->id);
                })
                ->applyDateFilterSchedule($filter, $from, $to)
                ->when(isset($key), function($query) use($key){
                    $query->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('id', 'like', "%{$value}%");
                        }
                    });
                })
                // ->withSum('transaction', 'admin_commission')
                // ->withSum('transaction', 'admin_expense')
                // ->withSum('transaction', 'delivery_fee_comission')
                ->orderBy('schedule_at', 'desc')->get();

            $data = [
                'orders'=>$orders,
                'search'=>$request->search??null,
                'from'=>(($filter == 'custom') && $from)?$from:null,
                'to'=>(($filter == 'custom') && $to)?$to:null,
                'zone'=>is_numeric($zone_id)?Helpers::get_zones_name($zone_id):null,
                'restaurant'=>is_numeric($restaurant_id)?Helpers::get_restaurant_name($restaurant_id):null,
                'customer'=>is_numeric($customer_id)?Helpers::get_customer_name($customer_id):null,
                'filter'=>$filter,
            ];

            if ($request->type == 'excel') {
                return Excel::download(new OrderReportExport($data), 'OrderReport.xlsx');
            } else if ($request->type == 'csv') {
                return Excel::download(new OrderReportExport($data), 'OrderReport.csv');
            }
        } catch(\Exception $e) {
            
            return response()->json([
               'status' => 'failed',
               'message' => "Something went wrong. ",
               'error'=>$e->getMessage()
             ], 500); 
        }

    }
}
