<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventsReview extends Model
{
    use HasFactory;


    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

     public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
