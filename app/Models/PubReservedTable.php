<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PubReservedTable extends Model
{
    use HasFactory;
    public $timestamps = true; // false if table has no created_at/updated_at

    public function PubTables()
    {
        return $this->hasMany(PubTable::class, 'table_nos')
            ->whereRaw('FIND_IN_SET(pub_tables.id, pub_reserved_tables.table_nos)');
    }

    public function pub()
    {
        return $this->belongsTo(Pub::class, 'pub_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function table_details()
    {
        return $this->hasMany(PubReservedTableDetail::class, 'order_id');
    }
}


