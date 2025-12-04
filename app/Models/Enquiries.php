<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enquiries extends Model
{
    //
    protected $table = 'enquiries';
    protected $fillable =['Fname','Lname', 'phone', 'email', 'travel_date','return_date', 'advert_id'];
    
    
}
