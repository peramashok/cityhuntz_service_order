<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Event extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function createdby()
    {
        return $this->belongsTo(User::class, 'created_by')
        ->select('id', 'f_name', 'l_name', 'email', 'phone', 'role_id');
    }

    public function updatedby()
    {
        return $this->belongsTo(User::class, 'updated_by')
        ->select('id', 'f_name', 'l_name', 'email', 'phone', 'role_id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function category()
    {
        return $this->belongsToMany(Category::class);
    }


    public function live_deals()
    {
        return $this->hasMany(EventLiveDeal::class,'event_id');
    }


    public function countries()
    {
        return $this->belongsToMany(FoodCountry::class, 'country_event', 'event_id', 'country_id');
    }

    public function event_countries()
    {
        return $this->belongsToMany(
            FoodCountry::class,
            'country_event',
            'event_id',
            'country_id'
        );
    }

    // public function scopeEventCountry($query, $country_id)
    // {
    //     if ($country_id != 'all' && !empty($country_id)) {

    //         return $query->whereHas('food_countries', function ($q) use ($country_id) {

    //             if (is_array($country_id)) {
    //                 $q->whereIn('event_countries.id', $country_id);
    //             } else {
    //                 $q->where('event_countries.id', $country_id);
    //             }

    //         });
    //     }

    //     return $query;
    // }


    public function scopeEventCountry($query, $country_id)
    {
        if ($country_id != 'all' && !empty($country_id)) {

            return $query->whereHas('event_countries', function ($q) use ($country_id) {

                if (is_array($country_id)) {
                    $q->whereIn('food_countries.id', $country_id);
                } else {
                    $q->where('food_countries.id', $country_id);
                }

            });
        }

        return $query;
    }

    public function scopeActive($query): mixed
    {
        $query =  $query->where('status', 1)->where('is_approved',1);
        return $query;
    }

    public function wishlist()
    {
        return $this->hasMany(Wishlist::class,'event_id');
    }

    public function stateInfo()
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function cityInfo()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
     public function livedeals()
    {
        return $this->hasMany(EventLiveDeal::class,'event_id');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_event');
    }


     public function scopeCategory($query, $category_id)
    {
        if($category_id != 'all' && !is_array($category_id)){
            return $query->whereHas('categories', function ($query) use ($category_id){
                $query->where('category_event.category_id', $category_id);
            });
        }
        return $query;
    }


    public function reviews()
    {
        return $this->hasMany(EventsReview::class);
    }
    public function reviews_comments()
    {
        return $this->reviews()->whereNotNull('comment');
    }


     public function photoes()
    {
        return $this->hasMany(EventsPhoto::class,'event_id');
    }

    public function features()
    {
        return $this->hasMany(EventsFeature::class,'event_id');
    }

    public function videos()
    {
        return $this->hasMany(EventsVideo::class,'event_id');
    }


     public function tickets()
    {
        return $this->hasMany(EventsTicket::class, 'event_id');
    }


}
