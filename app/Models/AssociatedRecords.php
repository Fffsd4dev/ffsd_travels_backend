<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssociatedRecords extends Model
{
    use HasFactory;
    use HasFactory;

    protected $fillable = [
        'flight_order_id',
        'reference',
        'creation_date',
        'origin_system_code',
        'flight_offer_id',
    ];

    public function flightOrder()
    {
        return $this->belongsTo(FlightOrders::class);
    }
}
