<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use Illuminate\Database\Eloquent\Model;
use App\Scopes\ZoneScope;
use Illuminate\Support\Facades\DB;

class Notification extends Model
{
    protected $casts = [
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    protected $appends = ['image_full_url'];

    public function getImageFullUrlAttribute(){
        $value = $this->image;
        return $value;
    }
    public function getDataAttribute()
    {
        return [
            "title"=> $this->title,
            "description"=> $this->description,
            "order_id"=> "",
            "image"=> $this->image,
            "type"=> "push_notification"
        ];
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '=', 1);
    }

    public function getCreatedAtAttribute($value)
    {
        return date('Y-m-d H:i:s',strtotime($value));
    }

    protected static function booted()
    {
        static::addGlobalScope(new ZoneScope);
    }

    protected static function boot()
    {
        parent::boot();
    }
}
