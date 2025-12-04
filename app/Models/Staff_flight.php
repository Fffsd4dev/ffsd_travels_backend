<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staff_flight extends Model
{
    use HasFactory;
    protected $fillable =['staff_full_name','flight_details','comp_id','staff_id','pnr','booked_time'];
}
