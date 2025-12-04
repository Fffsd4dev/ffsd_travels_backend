<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelerPricing extends Model
{
    use HasFactory;
    protected $fillable = [
        'flight_offer_id',
        'traveler_id',
        'fare_option',
        'traveler_type',
        'total_price',
        'base_price',
        'taxes',
        'refundable_taxes',
        'flight_pnr',
        'am_traveler_pricing_id',
    ];

    protected $casts = [
        'taxes' => 'array',
        'refundable_taxes' => 'array',
    ];

    public function flightOffer()
    {
        return $this->belongsTo(FlightOffers::class);
    }

    public function traveler()
    {
        return $this->belongsTo(Travelers::class);
    }

    public function fareDetails()
    {
        return $this->hasMany(FareDetail::class);
    }
}
