<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use App\Models\PriorityList;
use App\Models\BusinessSetting;
use App\Models\OrderTransaction;
use Illuminate\Support\Facades\DB;

class RestaurantLogic
{
    public static function calculate_positive_rating($ratings)
    {
        $total_submit = $ratings[0]+$ratings[1]+$ratings[2]+$ratings[3]+$ratings[4];
        $rating = (($ratings[0]+$ratings[1]) / ($total_submit?$total_submit:1)) *100;
        return ['rating'=>$rating, 'total'=>$total_submit];
    }
}
