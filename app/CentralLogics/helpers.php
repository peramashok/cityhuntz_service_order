<?php
namespace App\CentralLogics;

use App\Models\Allergy;
use App\Models\Nutrition;
use DateTime;
use Exception;
use DatePeriod;
use DateInterval;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Models\BusinessSetting;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail; 
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Laravelpkg\Laravelchk\Http\Controllers\LaravelchkController;
use App\Models\Zone;
use MatanYadaev\EloquentSpatial\Objects\Point;
use App\Models\Coupon;
use App\CentralLogics\RestaurantLogic;

class Helpers
{ 
    public static function error_processor($validator)
    {
        $err_keeper = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            array_push($err_keeper, ['code' => $index, 'message' => $error[0]]);
        }
        return $err_keeper;
    }

    public static function error_formater($key, $mesage, $errors = [])
    {
        $errors[] = ['code' => $key, 'message' => $mesage];

        return $errors;
    }

    public static function schedule_order()
    {
        return (bool)Helpers::getSettingsDataFromConfig(settings: 'schedule_order')?->value;
    }

    public static function remove_invalid_charcaters($str)
    {
        return str_ireplace(['\'', '"',';', '<', '>'], ' ', $str);
    }

    public static function variation_price($product, $variations)
    {
        $match = $variations;
        $result = 0;
            foreach($product as $product_variation){
                foreach($product_variation['values'] as $option){
                    foreach($match as $variation){
                        if($product_variation['name'] == $variation['name'] && isset($variation['values']) && in_array($option['label'], $variation['values']['label'])){
                            $result += $option['optionPrice'];
                        }
                    }
                }
            }

        return $result;
    }
 

    public static function get_business_settings($name, $json_decode = true)
    {
        
        $config = null;
        $settings = Cache::rememberForever('business_settings_all_data', function () {
            return BusinessSetting::all();
        });

        $data = $settings?->firstWhere('key', $name);
        if (isset($data)) {
            $config = $json_decode? json_decode($data['value'], true) : $data['value'];
            if (is_null($config)) {
                $config = $data['value'];
            }
        }
        return $config;
    }

       
    public static function dataUpdateOrInsert($key, $value)
    {
        $businessSetting = DataSetting::where(['key' => $key['key'],'type' => $key['type']])->first();
        if ($businessSetting) {
            $businessSetting->value = $value['value'];
            $businessSetting->save();
        } else {
            $businessSetting = new DataSetting();
            $businessSetting->key = $key['key'];
            $businessSetting->type = $key['type'];
            $businessSetting->value = $value['value'];
            $businessSetting->save();
        }
    }


    public static function getRolePermissions($val){
        $roleData=Role::find(auth()->user()->role_id);
        if ($roleData && $roleData->hasPermissionTo($val)) {
            return true;
        }
        return false;
    }

     public static function get_view_keys()
    {
        $keys = BusinessSetting::whereIn('key', ['toggle_veg_non_veg', 'toggle_dm_registration', 'toggle_restaurant_registration'])->get();
        $data = [];
        foreach ($keys as $key) {
            $data[$key->key] = (bool)$key->value ?? 0;
        }
        return $data;
    }

    public static function getPaymentSettings(){
        $paymentSettingData=PaymentSetting::where('id', 1)->first();
        return $paymentSettingData;
    }
    public static function getReferralStatus(){
        $businessSettingData=BusinessSetting::where('key', 'ref_earning_status')->first();
        return $businessSettingData;
    }

    public static function imageUploadToDrive($file, $parentId, $relativePath, $image_profile_pic)
    {
        $token = env('OBJECT_API_KEY');
        $url = env('OBJECT_APIURL');
        $objectUrl = env('OBJECT_URL');
        
        if (!$file) {
            return [
                'success' => false,
                'message' => 'No file provided.',
                'url' => null
            ];
        }
        try {
            $response = Http::withToken($token)
                ->attach('file', file_get_contents($file), $image_profile_pic)
                ->post($url . '/uploads', [
                    'parentId' => $parentId,
                    'relativePath' => $relativePath,
                ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'message' => $response->body(),
                    'url' => null
                ];
            }

            $imageresponse = $response->json();

            if (isset($imageresponse['fileEntry']['url'])) {
                return [
                    'success' => true,
                    'message' => 'File uploaded successfully.',
                    'url' => $objectUrl . $imageresponse['fileEntry']['url']
                ];
            }

            return [
                'success' => false,
                'message' => 'Invalid response from server.',
                'url' => null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'url' => null
            ];
        }
    }
    public static function getDisk()
    {
        $config=self::get_business_settings('local_storage');

        return isset($config)?($config==0?'s3':'public'):'public';
    }  

    public static function address_data_formatting($data)
    {
        foreach ($data as $key=>$item) {
            $data[$key]['zone_ids'] = array_column(Zone::query()->whereContains('coordinates', new Point($item->latitude, $item->longitude, POINT_SRID))->latest()->get(['id'])->toArray(), 'id');
        }
        return $data;
    }

    public static function getSettingsDataFromConfig($settings,$relations=[])
    {
        try {
            if (!config($settings.'_conf')){
                $data = BusinessSetting::where('key',$settings)->with($relations)->first();
                Config::set($settings.'_conf', $data);
            }
            else{
                $data = config($settings.'_conf');
            }
            return $data;
        } catch (\Throwable $th) {
            return null;
        }
    }

    public static function restaurant_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        $cuisines=[];
        $extra_packaging_data = \App\Models\BusinessSetting::where('key', 'extra_packaging_charge')->first()?->value ?? 0;

        if ($multi_data == true) {
            foreach ($data as $item) {
                $item['foods']  =  $item->foods()->active()->take(5)->get(['id','image' ,'name']);
                $item->load('cuisine');
                $restaurant_id= (string)$item->id;

                $item['coupons'] = Coupon::Where(function ($q) use ($restaurant_id) {
                    $q->Where('coupon_type', 'restaurant_wise')->whereJsonContains('data', [$restaurant_id])
                        ->where(function ($q1)  {
                            $q1->WhereJsonContains('customer_id', ['all']);
                        });
                })->orwhere('restaurant_id', $restaurant_id)
                ->active()
                ->valid()
                ->take(10)
                ->get();

                if( $item->restaurant_model == 'subscription'  && isset($item->restaurant_sub)){
                    $item['self_delivery_system'] = (int) $item->restaurant_sub->self_delivery;
                }

                $item['delivery_fee'] = self::getDeliveryFee($item);

                $item['restaurant_status'] = (int) $item->status;
                $item['cuisine'] = $item->cuisine;

                if ($item->opening_time) {
                    $item['available_time_starts'] = $item->opening_time->format('H:i');
                    unset($item['opening_time']);
                }
                if ($item->closeing_time) {
                    $item['available_time_ends'] = $item->closeing_time->format('H:i');
                    unset($item['closeing_time']);
                }

                $reviewsInfo = $item->reviews()
                ->selectRaw('avg(reviews.rating) as average_rating, count(reviews.id) as total_reviews, food.restaurant_id')
                ->groupBy('food.restaurant_id')
                ->first();

                $item['ratings'] = $item?->ratings ?? [];
                $item['avg_rating'] = (float)  $reviewsInfo?->average_rating ?? 0;
                $item['rating_count'] = (int)   $reviewsInfo?->total_reviews ?? 0;

                $positive_rating = RestaurantLogic::calculate_positive_rating($item['rating']);

                $item['positive_rating'] = (int) $positive_rating['rating'];

                $item['customer_order_date'] =   (int) $item?->restaurant_config?->customer_order_date;
                $item['customer_date_order_sratus'] =   (bool) $item?->restaurant_config?->customer_date_order_sratus;
                $item['instant_order'] =   (bool) $item?->restaurant_config?->instant_order;
                $item['halal_tag_status'] =   (bool) $item?->restaurant_config?->halal_tag_status;
                $item['current_opening_time'] = self::getNextOpeningTime($item['schedules']) ?? 'closed';
                $item['is_extra_packaging_active'] =   (bool) ($extra_packaging_data == 1 ? $item?->restaurant_config?->is_extra_packaging_active:false);
                $item['extra_packaging_status'] =   (bool) ($item['is_extra_packaging_active']  == 1   ? $item?->restaurant_config?->extra_packaging_status:false);
                $item['extra_packaging_amount'] =   (float)( $item['is_extra_packaging_active']  == 1 ? $item?->restaurant_config?->extra_packaging_amount:0);

                $item['is_dine_in_active'] =   (bool) $item?->restaurant_config?->dine_in;
                $item['schedule_advance_dine_in_booking_duration'] =   (int)  $item?->restaurant_config?->schedule_advance_dine_in_booking_duration;
                $item['schedule_advance_dine_in_booking_duration_time_format'] =   $item?->restaurant_config?->schedule_advance_dine_in_booking_duration_time_format ?? 'min';

                $item['characteristics'] = $item->characteristics()->pluck('characteristic')->toArray();
                unset($item['campaigns']);
                unset($item['pivot']);
                unset($item['rating']);
                unset($item['restaurant_config']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if( $data->restaurant_model == 'subscription'  && isset($data->restaurant_sub)){
                $data['self_delivery_system'] = (int) $data->restaurant_sub->self_delivery;
            }
            $data['restaurant_status'] = (int) $data->status;
            if ($data->opening_time) {
                $data['available_time_starts'] = $data->opening_time->format('H:i');
                unset($data['opening_time']);
            }
            if ($data->closeing_time) {
                $data['available_time_ends'] = $data->closeing_time->format('H:i');
                unset($data['closeing_time']);
            }

            $data['foods']  =  $data->foods()->active()->take(5)->get(['id','image' ,'name']);
            $restaurant_id= (string)$data->id;
            $data['coupons'] = Coupon::Where(function ($q) use ($restaurant_id) {
                $q->Where('coupon_type', 'restaurant_wise')->whereJsonContains('data', [$restaurant_id])
                    ->where(function ($q1)  {
                        $q1->WhereJsonContains('customer_id', ['all']);
                    });
            })->orwhere('restaurant_id',$restaurant_id)
            ->active()
            ->valid()
            ->take(10)
            ->get();

            $data->load(['cuisine']);
            $data['cuisine'] = $data->cuisine;

            $reviewsInfo = $data->reviews()
            ->selectRaw('avg(reviews.rating) as average_rating, count(reviews.id) as total_reviews, food.restaurant_id')
            ->groupBy('food.restaurant_id')
            ->first();
            $data['ratings'] = $data?->rating ?? [];
            $data['avg_rating'] = (float)  $reviewsInfo?->average_rating ?? 0;
            $data['rating_count'] = (int)   $reviewsInfo?->total_reviews ?? 0;

            $positive_rating = RestaurantLogic::calculate_positive_rating($data['rating']);
            $data['positive_rating'] = (int) $positive_rating['rating'];

            $data['customer_order_date'] =   (int) $data?->restaurant_config?->customer_order_date;
            $data['customer_date_order_sratus'] =   (bool) $data?->restaurant_config?->customer_date_order_sratus;
            $data['instant_order'] =   (bool) $data?->restaurant_config?->instant_order;
            $data['halal_tag_status'] =   (bool) $data?->restaurant_config?->halal_tag_status;
            $data['is_extra_packaging_active'] =   (bool) ($extra_packaging_data == 1 ? $data?->restaurant_config?->is_extra_packaging_active:false);
            $data['extra_packaging_status'] =   (bool)  ($data['is_extra_packaging_active'] == 1  ? $data?->restaurant_config?->extra_packaging_status:false);
            $data['extra_packaging_amount'] =   (float)  ($data['is_extra_packaging_active'] == 1 ? $data?->restaurant_config?->extra_packaging_amount:0);
            $data['delivery_fee'] = self::getDeliveryFee($data);
            $data['current_opening_time'] = self::getNextOpeningTime($data['schedules']) ?? 'closed';

            $data['is_dine_in_active'] =   (bool) $data?->restaurant_config?->dine_in;
            $data['schedule_advance_dine_in_booking_duration'] =   (int)  $data?->restaurant_config?->schedule_advance_dine_in_booking_duration;
            $data['schedule_advance_dine_in_booking_duration_time_format'] =   $data?->restaurant_config?->schedule_advance_dine_in_booking_duration_time_format ?? 'min';
            $data['tags'] = $data->tags()->pluck('tag')->toArray();


            $data['characteristics'] = $data->characteristics()->pluck('characteristic')->toArray();
            unset($data['rating']);
            unset($data['campaigns']);
            unset($data['pivot']);
            unset($data['restaurant_config']);
        }

        return $data;
    }


    public static function getDeliveryFee($restaurant): string
    {
        if(!request()->header('latitude') || !request()->header('longitude')){
            return 'out_of_range';
        }
            $zone = Zone::where('id', $restaurant->zone_id)->whereContains('coordinates', new Point(request()->header('latitude') && request()->header('longitude'), POINT_SRID))->first();
        if(!$zone) {
            return 'out_of_range';
        }
 
        if(isset($restaurant->distance) && $restaurant->distance > 0){
            $distance = $restaurant->distance / 1000;
            $distance=   round($distance,5);
        }
        elseif( $restaurant->latitude &&  $restaurant->longitude){

        $originCoordinates =[
            $restaurant->latitude,
            $restaurant->longitude
        ];
        $destinationCoordinates =[
            request()->header('latitude') ,
            request()->header('longitude')
        ];
            $distance = self::get_distance($originCoordinates, $destinationCoordinates);
            $distance=   round($distance,5);
        } else {
            return 'out_of_range';
        }

        if($restaurant['self_delivery_system'] ==  1){

            if($restaurant->free_delivery == 1){
                return 'free_delivery';
            }
            if($restaurant->free_delivery_distance_status == 1 &&  $distance <= $restaurant->free_delivery_distance_value){
                return 'free_delivery';
            }

            $per_km_shipping_charge = $restaurant->per_km_shipping_charge ?? 0 ;
            $minimum_shipping_charge = $restaurant->minimum_shipping_charge ?? 0;
            $maximum_shipping_charge = $restaurant->maximum_shipping_charge ?? 0;
            $extra_charges= 0;
            $increased= 0;


        }
        else{
        $free_delivery_distance = BusinessSetting::where('key', 'free_delivery_distance')->first()?->value ?? 0;
            if($distance <= $free_delivery_distance){
                return 'free_delivery';
            }
            $per_km_shipping_charge = $zone->per_km_shipping_charge ?? 0;
            $minimum_shipping_charge = $zone->minimum_shipping_charge ?? 0;
            $maximum_shipping_charge = $zone->maximum_shipping_charge ?? 0;
            $increased= 0;
            if($zone->increased_delivery_fee_status == 1){
                $increased=$zone->increased_delivery_fee ?? 0;
            }
            $data = self::vehicle_extra_charge(distance_data:$distance);
            $extra_charges = (float) (isset($data) ? $data['extra_charge']  : 0);

        }

            $original_delivery_charge = ($distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $distance * $per_km_shipping_charge + $extra_charges  : $minimum_shipping_charge + $extra_charges;
        if($increased > 0  && $original_delivery_charge > 0){
                $increased_fee = ($original_delivery_charge * $increased) / 100;
                $original_delivery_charge = $original_delivery_charge + $increased_fee;
        }
        return (string) $original_delivery_charge ;
    }


    public static function get_distance(array $originCoordinates,array $destinationCoordinates, $unit = 'K'): float
    {
        $lat1 = (float) $originCoordinates[0];
        $lat2 = (float) $destinationCoordinates[0];
        $lon1 = (float) $originCoordinates[1];
        $lon2 = (float) $destinationCoordinates[1];

        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            return 0;
        } else {
            $theta = $lon1 - $lon2;
            $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $unit = strtoupper($unit);
            if ($unit == "K") {
                return ($miles * 1.609344);
            } else if ($unit == "N") {
                return ($miles * 0.8684);
            } else {
                return $miles;
            }
        }
    }
    
    public static function get_restaurant_discount($restaurant)
    {
        if ($restaurant->discount) {
            if (date('Y-m-d', strtotime($restaurant->discount->start_date)) <= now()->format('Y-m-d') && date('Y-m-d', strtotime($restaurant->discount->end_date)) >= now()->format('Y-m-d') && date('H:i', strtotime($restaurant->discount->start_time)) <= now()->format('H:i') && date('H:i', strtotime($restaurant->discount->end_time)) >= now()->format('H:i')) {
                return [
                    'discount' => $restaurant->discount->discount,
                    'min_purchase' => $restaurant->discount->min_purchase,
                    'max_discount' => $restaurant->discount->max_discount
                ];
            }
        }
        return null;
    }

    
   public static function getNextOpeningTime($schedule) {
        $currentTime =now()->format('H:i');
        if ($schedule) {
            foreach($schedule as $entry) {
                if ($entry['day'] == now()->format('w')) {
                        if ($currentTime >= $entry['opening_time'] && $currentTime <= $entry['closing_time']) {
                            return $entry['opening_time'];
                        } elseif($currentTime < $entry['opening_time']){
                            return $entry['opening_time'];
                        }
                }
            }
        }
        return 'closed';
    }


     public static function get_full_url($path,$data,$type,$placeholder = null){
        $place_holders = [
            'default' => dynamicAsset('public/assets/admin/img/100x100/no-image-found.png'),
        ];

        if(isset($placeholder) && array_key_exists($placeholder, $place_holders)){
            return $place_holders[$placeholder];
        }elseif(array_key_exists($path, $place_holders)){
            return $place_holders[$path];
        }else{
            return $place_holders['default'];
        }

        return 'def.png';
    }


  public static function order_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        if ($multi_data) {
            foreach ($data as $item) {
                if (isset($item['restaurant'])) {
                    $item['restaurant_name'] = $item['restaurant']['name'];
                    $item['restaurant_address'] = $item['restaurant']['address'];
                    $item['restaurant_phone'] = $item['restaurant']['phone'];
                    $item['restaurant_lat'] = $item['restaurant']['latitude'];
                    $item['restaurant_lng'] = $item['restaurant']['longitude'];
                    $item['restaurant_logo'] = $item['restaurant']['logo'];
                    $item['restaurant_logo_full_url'] = $item['restaurant']['logo_full_url'];
                    $item['restaurant_delivery_time'] = $item['restaurant']['delivery_time'];
                    $item['vendor_id'] = $item['restaurant']['vendor_id'];
                    $item['chat_permission'] = $item['restaurant']['restaurant_sub']['chat'] ?? 0;
                    $item['restaurant_model'] = $item['restaurant']['restaurant_model'];
                    unset($item['restaurant']);
                } else {
                    $item['restaurant_name'] = null;
                    $item['restaurant_address'] = null;
                    $item['restaurant_phone'] = null;
                    $item['restaurant_lat'] = null;
                    $item['restaurant_lng'] = null;
                    $item['restaurant_logo'] = null;
                    $item['restaurant_logo_full_url'] = null;
                    $item['restaurant_delivery_time'] = null;
                    $item['restaurant_model'] = null;
                    $item['chat_permission'] = null;
                }
                $item['food_campaign'] = 0;
                foreach ($item->details as $d) {
                    if ($d->item_campaign_id != null) {
                        $item['food_campaign'] = 1;
                    }
                }

                $item['delivery_address'] = $item->delivery_address ? json_decode($item->delivery_address, true) : null;
                $item['details_count'] = (int)$item->details->count();
                unset($item['details']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if (isset($data['restaurant'])) {
                $data['restaurant_name'] = $data['restaurant']['name'];
                $data['restaurant_address'] = $data['restaurant']['address'];
                $data['restaurant_phone'] = $data['restaurant']['phone'];
                $data['restaurant_lat'] = $data['restaurant']['latitude'];
                $data['restaurant_lng'] = $data['restaurant']['longitude'];
                $data['restaurant_logo'] = $data['restaurant']['logo'];
                $data['restaurant_logo_full_url'] = $data['restaurant']['logo_full_url'];
                $data['restaurant_delivery_time'] = $data['restaurant']['delivery_time'];
                $data['vendor_id'] = $data['restaurant']['vendor_id'];
                $data['chat_permission'] = $data['restaurant']['restaurant_sub']['chat'] ?? 0;
                $data['restaurant_model'] = $data['restaurant']['restaurant_model'];
                unset($data['restaurant']);
            } else {
                $data['restaurant_name'] = null;
                $data['restaurant_address'] = null;
                $data['restaurant_phone'] = null;
                $data['restaurant_lat'] = null;
                $data['restaurant_lng'] = null;
                $data['restaurant_logo'] = null;
                $data['restaurant_logo_full_url'] = null;
                $data['restaurant_delivery_time'] = null;
                $data['chat_permission'] = null;
                $data['restaurant_model'] = null;
            }

            $data['food_campaign'] = 0;
            foreach ($data->details as $d) {
                if ($d->item_campaign_id != null) {
                    $data['food_campaign'] = 1;
                }
            }
            $data['delivery_address'] = $data->delivery_address ? json_decode($data->delivery_address, true) : null;
            $data['details_count'] = (int)$data->details->count();
            unset($data['details']);
        }
        return $data;
    }
}
