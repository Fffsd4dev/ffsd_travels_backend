<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlightOffers extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_order_id',
        'am_flight_offer_id',
        'type',
        'source',
        'non_homogeneous',
        'last_ticketing_date',
        'price',
        'pricing_options',
        'validating_airline_codes',
    ];

    protected $casts = [
        'price' => 'array',
        'pricing_options' => 'array',
        'validating_airline_codes' => 'array',
    ];

    public function flightOrder()
    {
        return $this->belongsTo(FlightOrders::class);
    }

    public function itineraries()
    {
        return $this->hasMany(Itinerary::class, 'flight_offer_id');
    }

    
    public function travelerPricings()
{
    return $this->hasMany(TravelerPricing::class, 'flight_offer_id');
}

}
