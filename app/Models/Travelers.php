<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Travelers extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_order_id',
        'date_of_birth',
        'gender',
        'first_name',
        'last_name',
        'documents',
        'contact',
        'am_traveler_id'//travelerId from amadeus
    ];

    protected $casts = [
        'documents' => 'array',
        'contact' => 'array',
    ];

    public function flightOrder()
    {
        return $this->belongsTo(FlightOrders::class);
    }

    public function travelerPricings()
    {
        return $this->hasMany(TravelerPricing::class);
    }

}
