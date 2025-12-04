<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FareDetail extends Model
{
    use HasFactory;
    protected $fillable = [
        'segment_id',
        'cabin',
        'fare_basis',
        'class',
        'included_checked_bags',
        'flight_order_id',
        'am_segment_id',
    ];
//flight_order_id here is the foreign key of the flight_offers table
    protected $casts = [
        'included_checked_bags' => 'array',
    ];

   
    public function segment()
    {
        return $this->belongsTo(Segments::class);
    }
}
