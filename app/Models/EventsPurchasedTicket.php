<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventsPurchasedTicket extends Model
{
    protected $guarded = [];
    use HasFactory, SoftDeletes;

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function ticket()
    {
        return $this->belongsTo(EventsTicket::class, 'ticket_id');
    }

    public function canceled_tickets()
    {
        return $this->hasMany(EventsCanceledTicket::class,'booking_id');
    }
}
