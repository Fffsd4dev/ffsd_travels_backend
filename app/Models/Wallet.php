<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;
  
    
    protected $fillable =['company_id','total_deposit','total_spent','balance', 'created_by_user_id'];
}


