<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class Cuisine extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'image'];
    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
    ];

    protected $appends = ['image_full_url'];

    public function getImageFullUrlAttribute(){
        $value = $this->image;
        
        return $value;
    }
    public function storage()
    {
        return $this->morphMany(Storage::class, 'data');
    }
    
    public function restaurants()
    {
        return $this->belongsToMany(Restaurant::class)->using('App\Models\Cuisine_restaurant');
    }

    protected static function boot()
    {
        parent::boot();
        static::created(function ($cuisine) {
            $cuisine->slug = $cuisine->generateSlug($cuisine->name);
            $cuisine->save();
        });
        static::saved(function ($model) {
            if($model->isDirty('image')){
                $value = Helpers::getDisk();

                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'image',
                ], [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function getNameAttribute($value){
        
        return $value;
    }
    protected static function booted()
    {
        
    }
    private function generateSlug($name)
    {
        $slug = Str::slug($name);
        if ($max_slug = static::where('slug', 'like',"{$slug}%")->latest('id')->value('slug')) {

            if($max_slug == $slug) return "{$slug}-2";

            $max_slug = explode('-',$max_slug);
            $count = array_pop($max_slug);
            if (isset($count) && is_numeric($count)) {
                $max_slug[]= ++$count;
                return implode('-', $max_slug);
            }
        }
        return $slug;
    }
}
