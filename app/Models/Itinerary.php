<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Itinerary extends Model
{
    use HasFactory;
    protected $fillable = [
        'flight_offer_id',
        'segments',
    ];

    protected $casts = [
        'segments' => 'array',
    ];

    public function flightOffer()
    {
        return $this->belongsTo(FlightOffers::class, 'flight_offer_id');
    }

    public function segments()
    {
        return $this->hasMany(Segments::class);
    }
}
