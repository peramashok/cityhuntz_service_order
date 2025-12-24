<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservedTable extends Model
{
    use HasFactory;
    public $timestamps = true; // false if table has no created_at/updated_at

    public function restaurantTables()
    {
        return $this->hasMany(RestaurantTable::class, 'table_nos')
            ->whereRaw('FIND_IN_SET(restaurant_tables.id, reserved_tables.table_nos)');
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }


    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    public function table_details()
    {
        return $this->hasMany(ReservedTableDetail::class, 'order_id');
    }
}


