<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PubsSchedule extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    
    protected $casts = [
        'day'=>'integer',
        'pub_id'=>'integer',
    ];

    public function pub()
    {
        return $this->belongsTo(Pub::class);
    }
}
