<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PubReservedTableDetail extends Model
{
    use HasFactory;

    protected $table = 'pub_reserved_tables_details';

    protected $primaryKey = 'id'; // change if different

    public $timestamps = true; // false if table has no created_at/updated_at

    protected $guarded = [];

    public function pubTables()
    {
        return $this->belongsTo(PubTable::class, 'table_id');
    }
}
