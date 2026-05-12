<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventsTicket extends Model
{
      protected $guarded = [];
    use HasFactory, SoftDeletes;

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    
    public function purchasedTickets()
    {
        return $this->hasMany(EventsPurchasedTicket::class, 'ticket_id');
    }
}
