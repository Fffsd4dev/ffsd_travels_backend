<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Segments extends Model
{
    use HasFactory;
    protected $fillable = [
        'itinerary_id',
        'departure',
        'arrival',
        'carrier_code',
        'number',
        'aircraft',
        'duration',
        'number_of_stops',
        'co2_emissions',
        'am_segment_id',
    ];

    protected $casts = [
        'departure' => 'array',
        'arrival' => 'array',
        'aircraft' => 'array',
        'co2_emissions' => 'array',
    ];

    public function itinerary()
    {
        return $this->belongsTo(Itinerary::class);
    }
}
