<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventsNotification extends Model
{
    use HasFactory;

    protected $guarded=[];

    public function createdby()
    {
        return $this->belongsTo(User::class, 'created_by')
            ->select('id', 'f_name', 'l_name', 'email', 'phone', 'role_id');
    }


    public function user_notifications()
    {
        return $this->hasMany(UserNotification::class, 'notification_id')->where('type', 'E');
    }
}
