<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservedTableDetail extends Model
{
    use HasFactory;

    protected $table = 'reserved_tables_details';

    protected $primaryKey = 'id'; // change if different

    public $timestamps = true; // false if table has no created_at/updated_at

    protected $guarded = [];


    public function restaurantTables()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_nos');
    }
}
