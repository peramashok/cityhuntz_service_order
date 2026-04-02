<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
 

class WithdrawRequest extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'amount'=>'float',
        'user_id'=>'integer',
        'withdrawal_method_id'=>'integer',
        'approved'=>'integer'
    ];

    public function user_info(){
        return $this->belongsTo(User::class, 'user_id');
    }
    public function method(){
        return $this->belongsTo(WithdrawalMethod::class,'withdrawal_method_id');
    }

    public function disbursementMethod(){
        return $this->belongsTo(DisbursementWithdrawalMethod::class,'withdrawal_method_id');
    }

    public function vendor(){
        return $this->belongsTo(User::class, 'user_id');
    }
    
}
