<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseTracker extends Model
{
    use HasFactory;
    protected $fillable = ['company_id','flight_offer_id','flight_details','balance','created_by_user_id'];
}
